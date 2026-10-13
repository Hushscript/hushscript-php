<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use GuzzleHttp\Psr7\Response;
use Hushscript\Client;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\ResumeMismatchError;
use Hushscript\Exception\UnsupportedMediaError;
use Hushscript\Exception\UploadError;
use Hushscript\Tests\Support\Deadline;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Tests\Support\RecordingParts;
use Hushscript\Tests\Support\RefusingWrapper;
use Hushscript\Tests\Support\ScriptedStream;
use Hushscript\Tests\Support\SlowRead;
use Hushscript\Tests\Support\Tokens;
use Hushscript\Transcribe\Probe;
use Hushscript\Transcribe\Resume;
use Hushscript\Transcribe\Source;
use Hushscript\Transcribe\StreamView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A stream as the input of transcribe and resume: one that can seek and one that cannot (spec 6.1, 7.3, 8.1, 8.2). */
final class StreamInputTest extends TestCase
{
    /** @var list<resource> */
    private array $open = [];

    protected function setUp(): void
    {
        // A regression that loops forever fails the test instead of hanging the run.
        Deadline::arm();
    }

    protected function tearDown(): void
    {
        Deadline::disarm();
        foreach ($this->open as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @return resource
     */
    private function memory(string $bytes)
    {
        $handle = fopen('php://memory', 'r+b');
        self::assertNotFalse($handle);
        fwrite($handle, $bytes);
        rewind($handle);
        $this->open[] = $handle;

        return $handle;
    }

    /**
     * A pipe: it reads in whatever pieces the other process writes, and it cannot seek.
     *
     * @return resource
     */
    private function pipe(string $path)
    {
        $handle = popen('cat '.escapeshellarg($path).' 2>/dev/null', 'r');
        self::assertNotFalse($handle);
        self::assertFalse(stream_get_meta_data($handle)['seekable']);
        $this->open[] = $handle;

        return $handle;
    }

    /**
     * A harness whose parts are recorded, with its media file's bytes. A `$size` makes the create answer describe that many bytes in the harness's part size.
     *
     * @return array{PipelineHarness, RecordingParts, string}
     */
    private static function harness(?int $size = null): array
    {
        $clock = new FakeClock();
        $parts = new RecordingParts($clock);
        $harness = new PipelineHarness(clock: $clock, transport: $parts);
        if ($size !== null) {
            $harness->api->on('POST', '/v1/uploads', static fn (): Response => PipelineHarness::json(200, $harness->createBody((int) ceil($size / $harness->partSize))));
        }

        return [$harness, $parts, (string) file_get_contents($harness->path())];
    }

    /** @return array<mixed> */
    private static function createBody(PipelineHarness $harness): array
    {
        $body = json_decode((string) $harness->api->to('POST', '/v1/uploads')[0]->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }

    public function testASeekableStreamIsMeasuredProbedAndUploadedLikeAFile(): void
    {
        [$harness, $parts, $bytes] = self::harness();
        $stream = $this->memory($bytes);
        fseek($stream, 7);

        $transcript = $harness->pipeline->transcribe($stream, []);

        self::assertSame('tr_1', $transcript->id);
        $body = self::createBody($harness);
        self::assertSame(strlen($bytes), $body['size_bytes']);
        self::assertSame(21, $body['duration_seconds'], 'the probe read the stream');
        self::assertCount(3, $parts->requests);
        self::assertSame($bytes, $parts->joined());
        self::assertTrue(is_resource($stream), 'the SDK never closes the caller\'s stream');
    }

    public function testASeekableStreamOnAFileHandleIsReadFromTheStartWhateverItsPosition(): void
    {
        [$harness, $parts, $bytes] = self::harness();
        $handle = fopen($harness->path(), 'rb');
        self::assertNotFalse($handle);
        $this->open[] = $handle;
        fseek($handle, 1000);

        $harness->pipeline->transcribe($handle, ['concurrency' => 2]);

        self::assertSame($bytes, $parts->joined());
        self::assertTrue(is_resource($handle));
    }

    public function testASeekableStreamTakesAGivenDurationInPlaceOfTheProbe(): void
    {
        [$harness] = self::harness();

        $harness->pipeline->transcribe($this->memory((string) file_get_contents($harness->path())), ['duration_seconds' => 40.2]);

        self::assertSame(41, self::createBody($harness)['duration_seconds']);
    }

    public function testAForwardOnlyStreamIsUploadedInOrderOnePartAtATimeFromMemory(): void
    {
        [$harness, $parts, $bytes] = self::harness();
        $stream = $this->pipe($harness->path());

        $transcript = $harness->pipeline->transcribe($stream, ['size' => strlen($bytes), 'duration_seconds' => 21, 'concurrency' => 4]);

        self::assertSame('tr_1', $transcript->id);
        self::assertSame(['PUT r2:1', 'PUT r2:2', 'PUT r2:3'], $parts->inner->lines());
        self::assertSame(1, $parts->inner->maxPending, 'a stream that cannot seek is read one part at a time');
        self::assertSame($bytes, $parts->joined());
        $body = self::createBody($harness);
        self::assertSame([strlen($bytes), 21], [$body['size_bytes'], $body['duration_seconds']]);
        self::assertTrue(is_resource($stream));
    }

    public function testAForwardOnlyPartIsHeldForItsRetries(): void
    {
        $clock = new FakeClock();
        $parts = new RecordingParts($clock, static fn (array $request, int $index): array => [$index === 0 ? new Response(500) : PipelineHarness::stored(), 0.0]);
        $harness = new PipelineHarness(clock: $clock, transport: $parts);
        $bytes = (string) file_get_contents($harness->path());

        $transcript = $harness->pipeline->transcribe($this->pipe($harness->path()), ['size' => strlen($bytes), 'duration_seconds' => 21]);

        self::assertSame('tr_1', $transcript->id);
        self::assertGreaterThan(3, count($parts->requests), 'the failed part was sent again');
        $first = substr($bytes, 0, $harness->partSize);
        self::assertSame($first, $parts->requests[0]['body']);
        self::assertSame($first, $parts->requests[1]['body'], 'the retry sends the part it held, not the next bytes of the stream');
        self::assertSame($bytes, $parts->joined());
    }

    public function testAForwardOnlyPartThatIsSlowToArriveKeepsTheJobAlive(): void
    {
        $clock = new FakeClock();
        $parts = new RecordingParts($clock);
        $harness = new PipelineHarness(clock: $clock, transport: $parts);
        $bytes = (string) file_get_contents($harness->path());
        $stream = $this->pipe($harness->path());
        $beats = '/v1/uploads/job_1/heartbeat';
        /** @var list<array{int, int}> $seen */
        $seen = [];
        SlowRead::attach($stream, static function () use ($clock, $harness, $parts, $beats, &$seen): void {
            $clock->time += 30.0;
            $seen[] = [count($parts->requests), count($harness->api->to('POST', $beats))];
        });

        try {
            $harness->pipeline->transcribe($stream, ['size' => strlen($bytes), 'duration_seconds' => 21]);
        } finally {
            SlowRead::$onChunk = null;
        }

        $pingedWhileTheFirstPartWasArriving = array_filter($seen, static fn (array $look): bool => $look[0] === 0 && $look[1] >= 1);
        self::assertNotSame([], $pingedWhileTheFirstPartWasArriving, 'a ping went out before the first part was sent');
        self::assertSame($bytes, $parts->joined());
    }

    public function testAForwardOnlyPartThatDirectStorageRefusesGoesThroughTheApiFromTheBytesItHeld(): void
    {
        $clock = new FakeClock();
        $parts = new RecordingParts($clock, static fn (array $request): array => [str_contains($request['url'], 'r2.example') ? new Response(403) : PipelineHarness::stored(), 0.0]);
        $harness = new PipelineHarness(clock: $clock, transport: $parts);
        $bytes = (string) file_get_contents($harness->path());
        $fresh = [['n' => 1, 'url' => PipelineHarness::url(1, '&fresh=1')]];
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(200, ['part_size_bytes' => $harness->partSize, 'total_parts' => 3, 'part_urls' => $fresh, 'next_part_number' => null]));

        $harness->pipeline->transcribe($this->pipe($harness->path()), ['size' => strlen($bytes), 'duration_seconds' => 21]);

        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'), 'one fresh page, not one per part');
        self::assertSame(['PUT r2:1', 'PUT r2:1', 'PUT api:1', 'PUT api:2', 'PUT api:3'], $parts->inner->lines());
        self::assertSame($bytes, $parts->joined());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function missingForAForwardOnlyStream(): iterable
    {
        yield 'neither' => [[]];
        yield 'only the size' => [['size' => 161037]];
        yield 'only the duration' => [['duration_seconds' => 21]];
    }

    /** @param  array<string, mixed>  $options */
    #[DataProvider('missingForAForwardOnlyStream')]
    public function testAForwardOnlyStreamNeedsItsSizeAndItsDuration(array $options): void
    {
        [$harness, , $bytes] = self::harness();
        $stream = $this->pipe($harness->path());

        try {
            $harness->pipeline->transcribe($stream, $options);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
            self::assertStringContainsString('size', $error->getMessage());
            self::assertStringContainsString('duration_seconds', $error->getMessage());
        }
        self::assertSame([], $harness->api->requests);
        self::assertSame(substr($bytes, 0, 16), fread($stream, 16), 'the stream was not read');
    }

    /** @return iterable<string, array{mixed}> */
    public static function badSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'a float' => [1000.0];
        yield 'a string' => ['1000'];
        yield 'a bool' => [true];
    }

    #[DataProvider('badSizes')]
    public function testASizeThatIsNotAPositiveWholeNumberIsInvalid(mixed $size): void
    {
        [$harness] = self::harness();

        try {
            $harness->pipeline->transcribe($this->memory((string) file_get_contents($harness->path())), ['size' => $size]);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
        }
        self::assertSame([], $harness->api->requests);
    }

    /** @return iterable<string, array{bool, int}> */
    public static function mismatches(): iterable
    {
        foreach ([[true, 'seekable'], [false, 'forward-only']] as [$seekable, $kind]) {
            yield $kind.', more than the stream holds' => [$seekable, 1000];
            yield $kind.', fewer than the stream holds' => [$seekable, -1000];
        }
    }

    #[DataProvider('mismatches')]
    public function testASizeThatIsNotTheStreamsIsAStreamSizeMismatchBeforeComplete(bool $seekable, int $delta): void
    {
        $real = (int) filesize((new PipelineHarness())->path());
        [$harness, $parts, $bytes] = self::harness($real + $delta);
        $stream = $seekable ? $this->memory($bytes) : $this->pipe($harness->path());

        try {
            $harness->pipeline->transcribe($stream, ['size' => $real + $delta, 'duration_seconds' => 21]);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('stream_size_mismatch', $error->errorCode);
            self::assertNotNull($error->idempotencyKey);
            self::assertNotNull($error->resumeToken);
        }
        self::assertSame([], $harness->api->to('POST', '/v1/uploads/job_1/complete'));
        self::assertTrue(is_resource($stream));
        self::assertNotEmpty($parts->requests, 'the upload had started');
    }

    public function testAnythingButAPathOrAStreamIsInvalid(): void
    {
        [$harness] = self::harness();
        $closed = fopen('php://memory', 'rb');
        self::assertNotFalse($closed);
        fclose($closed);

        foreach ([42, 1.5, ['a.mp3'], null, new \stdClass(), $closed] as $file) {
            try {
                $harness->pipeline->transcribe($file, []);
                self::fail('expected a PreflightError');
            } catch (PreflightError $error) {
                self::assertSame('invalid_option', $error->errorCode);
            }
        }
        self::assertSame([], $harness->api->requests);
    }

    public function testAStreamThatCannotSeekIsNotProbed(): void
    {
        [$harness, , $bytes] = self::harness();
        $source = Source::stream($this->pipe($harness->path()), strlen($bytes));

        try {
            Probe::seconds($source);
            self::fail('expected an UnsupportedMediaError');
        } catch (UnsupportedMediaError $error) {
            self::assertSame('duration_unreadable', $error->errorCode);
            self::assertSame('Could not read the duration (the input is a stream that cannot seek); pass duration_seconds.', $error->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (MediaTest::probed() as $name => [$file]) {
            yield $name => [$file];
        }
    }

    #[DataProvider('fixtures')]
    public function testEveryFixtureIsProbedFromASeekableStreamAsFromItsPath(string $file): void
    {
        $path = (new PipelineHarness(file: $file))->path();
        $bytes = (string) file_get_contents($path);
        $memory = $this->memory($bytes);
        $handle = fopen($path, 'rb');
        self::assertNotFalse($handle);
        $this->open[] = $handle;

        $expected = Probe::seconds(Source::open($path));

        self::assertEqualsWithDelta($expected, Probe::seconds(Source::stream($memory, null)), 1e-9);
        self::assertEqualsWithDelta($expected, Probe::seconds(Source::stream($handle, null)), 1e-9);
        self::assertTrue(is_resource($memory));
        self::assertTrue(is_resource($handle));
        self::assertSame(0, fseek($memory, 0), 'the stream is still usable');
    }

    public function testAStreamThatCannotSeekIsOnlyReadFromItsStart(): void
    {
        [$harness, , $bytes] = self::harness();
        $source = Source::stream($this->pipe($harness->path()), strlen($bytes));

        self::assertSame(substr($bytes, 0, 4096), $source->head());
        self::assertSame(substr($bytes, 0, 5000), $source->readRange(0, 5000), 'the sniffed head is part of the first range');
        self::assertSame(substr($bytes, 5000, 100), $source->readRange(5000, 100));
        try {
            $source->readRange(0, 10);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('stream_not_seekable', $error->errorCode);
        }
        self::assertSame(substr($bytes, 5100), $source->readRange(5100, strlen($bytes) - 5100));
        self::assertTrue($source->atEnd());
    }

    /**
     * A stream that cannot seek and has no bytes yet: a read of it returns nothing without the stream having ended.
     *
     * @return array{resource, resource}  the reader and the writer
     */
    private function idleSocket(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$reader, $writer] = $pair;
        stream_set_blocking($reader, false);
        array_push($this->open, $reader, $writer);

        return [$reader, $writer];
    }

    public function testAStreamThatReturnsNothingWithoutEndingIsAReadFailureNotItsEnd(): void
    {
        [$reader, $writer] = $this->idleSocket();
        fwrite($writer, 'abcde');
        $source = Source::stream($reader, 5);
        self::assertSame('abcde', $source->readRange(0, 5));

        try {
            $source->atEnd();
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('source_read_failed', $error->errorCode);
        }
    }

    public function testAStreamThatHoldsMoreThanItDeclaredIsNotAtItsEnd(): void
    {
        [$reader, $writer] = $this->idleSocket();
        fwrite($writer, 'abcdef');
        $source = Source::stream($reader, 5);
        self::assertSame('abcde', $source->readRange(0, 5));

        self::assertFalse($source->atEnd());
    }

    public function testAForwardOnlyStreamThatCannotBeReadWhileResumingIsASourceReadFailedNotAPreflightError(): void
    {
        [$harness, , , $token] = self::resuming();
        [$reader] = $this->idleSocket();

        try {
            $harness->pipeline->resume($token, $reader, []);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('source_read_failed', $error->errorCode);
            self::assertNotNull($error->resumeToken);
        }
    }

    public function testAForwardOnlyStreamLongerThanTheTokenIsAStreamSizeMismatchOnResume(): void
    {
        [$harness, , $bytes] = self::harness(null);
        $declared = strlen($bytes) - 1000;
        $token = Resume::state(PipelineHarness::JOB, 'key-1', $declared, 21)->encode();
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'uploading']));
        $harness->api->on('POST', '/v1/uploads', static fn (): Response => PipelineHarness::json(200, $harness->createBody((int) ceil($declared / $harness->partSize))));

        try {
            $harness->pipeline->resume($token, $this->pipe($harness->path()), []);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('stream_size_mismatch', $error->errorCode);
        }
        self::assertSame([], $harness->api->to('POST', '/v1/uploads/job_1/complete'));
    }

    public function testASeekableStreamThatCannotBeWindowedIsNotProbedThroughAFileNamedStream(): void
    {
        [$harness, , $bytes] = self::harness();
        $dir = sys_get_temp_dir().'/hs-probe-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/stream', $bytes);
        $wrapper = 'hushscript-view';
        $replaced = in_array($wrapper, stream_get_wrappers(), true);
        if ($replaced) {
            stream_wrapper_unregister($wrapper);
        }
        stream_wrapper_register($wrapper, RefusingWrapper::class);
        $here = (string) getcwd();
        chdir($dir);

        try {
            Probe::seconds(Source::stream($this->memory($bytes), null));
            self::fail('expected an UnsupportedMediaError');
        } catch (UnsupportedMediaError $error) {
            self::assertSame('duration_unreadable', $error->errorCode);
        } finally {
            chdir($here);
            stream_wrapper_unregister($wrapper);
            if ($replaced) {
                stream_wrapper_register($wrapper, StreamView::class);
            }
            unlink($dir.'/stream');
            rmdir($dir);
        }
    }

    /** @return array{PipelineHarness, RecordingParts, string, string} */
    private static function resuming(): array
    {
        [$harness, $parts, $bytes] = self::harness();
        self::uploadingThenDone($harness);

        return [$harness, $parts, $bytes, Resume::state(PipelineHarness::JOB, 'key-1', strlen($bytes), 21)->encode()];
    }

    private static function uploadingThenDone(PipelineHarness $harness): void
    {
        $harness->api->on('GET', '/v1/jobs/job_1', [
            PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'uploading']),
            PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'done', 'transcript_id' => 'tr_1']),
        ]);
    }

    public function testAForwardOnlyStreamResumesWithTheSizeOfTheToken(): void
    {
        [$harness, $parts, $bytes, $token] = self::resuming();
        $stream = $this->pipe($harness->path());

        $transcript = $harness->pipeline->resume($token, $stream, []);

        self::assertSame('tr_1', $transcript->id);
        self::assertSame($bytes, $parts->joined());
        self::assertSame(1, $parts->inner->maxPending);
        $body = self::createBody($harness);
        self::assertSame(['key-1', strlen($bytes), 21], [$body['idempotency_key'], $body['size_bytes'], $body['duration_seconds']]);
        self::assertTrue(is_resource($stream));
    }

    public function testASeekableStreamResumesFromItsStart(): void
    {
        [$harness, $parts, $bytes, $token] = self::resuming();
        $stream = $this->memory($bytes);
        fseek($stream, 100);

        $harness->pipeline->resume($token, $stream, ['concurrency' => 2]);

        self::assertSame($bytes, $parts->joined());
        self::assertTrue(is_resource($stream));
    }

    public function testASeekableStreamOfAnotherSizeIsRefusedBeforeAnyRequest(): void
    {
        [$harness, , $bytes, $token] = self::resuming();

        try {
            $harness->pipeline->resume($token, $this->memory($bytes.'x'), []);
            self::fail('expected a ResumeMismatchError');
        } catch (ResumeMismatchError $error) {
            self::assertSame('resume_file_mismatch', $error->errorCode);
        }
        self::assertSame([], $harness->api->requests);
    }

    public function testAForwardOnlyStreamThatIsShorterThanTheTokenIsAStreamSizeMismatch(): void
    {
        [$harness, , $bytes] = self::harness(null);
        $declared = strlen($bytes) + 5000;
        $token = Resume::state(PipelineHarness::JOB, 'key-1', $declared, 21)->encode();
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'uploading']));
        $harness->api->on('POST', '/v1/uploads', static fn (): Response => PipelineHarness::json(200, $harness->createBody((int) ceil($declared / $harness->partSize))));

        try {
            $harness->pipeline->resume($token, $this->pipe($harness->path()), []);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('stream_size_mismatch', $error->errorCode);
        }
        self::assertSame([], $harness->api->to('POST', '/v1/uploads/job_1/complete'));
    }

    public function testAStreamIsNotReadWhenTheJobHasLeftUploading(): void
    {
        [$harness, $parts, $bytes] = self::harness();
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, PipelineHarness::jobBody()));
        $token = Resume::state(PipelineHarness::JOB, 'key-1', strlen($bytes), 21)->encode();
        $stream = $this->pipe($harness->path());

        $transcript = $harness->pipeline->resume($token, $stream, []);

        self::assertSame('tr_1', $transcript->id);
        self::assertSame([], $parts->requests);
        self::assertSame([], $harness->api->to('POST', '/v1/uploads'));
        self::assertSame(substr($bytes, 0, 16), fread($stream, 16), 'the stream was left where it was');
    }

    public function testAResumeAnythingButAPathOrAStreamIsInvalid(): void
    {
        [$harness, , , $token] = self::resuming();

        try {
            $harness->pipeline->resume($token, 42, []);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
        }
        self::assertSame([], $harness->api->requests);
    }

    public function testTheClientTakesAStreamForTranscribeAndResume(): void
    {
        [$harness, $parts, $bytes] = self::harness();
        $token = Resume::state(PipelineHarness::JOB, 'key-1', strlen($bytes), 21)->encode();
        $client = new Client(Tokens::TEST, ['base_url' => PipelineHarness::BASE, 'clock' => new FakeClock(), 'http_client' => $harness->api, 'part_transport' => $parts]);
        $transcribed = $client->transcribe($this->memory($bytes));
        $parts->requests = [];
        self::uploadingThenDone($harness);

        $resumed = $client->resume($token, $this->memory($bytes), ['wait_timeout' => 30]);

        self::assertSame('tr_1', $transcribed->id);
        self::assertSame('tr_1', $resumed->id);
        self::assertSame($bytes, $parts->joined());
    }

    public function testAStreamStaysOpenWhenTheCreateFails(): void
    {
        [$harness, $parts, $bytes] = self::harness();
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(500));
        $stream = $this->memory($bytes);

        try {
            $harness->pipeline->transcribe($stream, []);
            self::fail('expected an error');
        } catch (\Hushscript\Exception\ApiError $error) {
            self::assertSame(\Hushscript\Exception\ApiError::class, $error::class);
            self::assertSame('http_500', $error->errorCode);
            self::assertSame(500, $error->status);
            self::assertNotNull($error->idempotencyKey);
            $creates = $harness->api->to('POST', '/v1/uploads');
            self::assertCount(4, $creates);
            foreach ($creates as $request) {
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                self::assertSame($body['idempotency_key'], $error->idempotencyKey);
            }
        }
        self::assertTrue(is_resource($stream));
        self::assertSame([], $parts->requests);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function sizeBoundary(): iterable
    {
        yield 'maximum' => [5_000_000_000, true];
        yield 'one byte above' => [5_000_000_001, false];
    }

    #[DataProvider('sizeBoundary')]
    public function testForwardOnlyStreamSizeLimitRunsBeforeRequests(int $size, bool $accepted): void
    {
        $harness = new PipelineHarness();
        $stream = $this->pipe($harness->path());
        $stop = new \DomainException('stop after preflight');
        try {
            $harness->pipeline->transcribe($stream, ['size' => $size, 'duration_seconds' => 20, 'on_resume_token' => static function () use ($stop): never {
                throw $stop;
            }]);
            self::fail('expected boundary outcome');
        } catch (\Throwable $error) {
            if ($accepted) {
                self::assertSame($stop, $error);
                self::assertSame(['GET /v1/account', 'POST /v1/uploads'], $harness->api->lines());
                $create = $harness->api->to('POST', '/v1/uploads')[0];
                $body = json_decode((string) $create->getBody(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                self::assertSame($size, $body['size_bytes']);
            } else {
                self::assertSame(\Hushscript\Exception\FileLimitError::class, $error::class);
                self::assertSame('size_out_of_range', $error->errorCode);
                self::assertNull($error->status);
                self::assertSame([], $harness->api->requests);
            }
        }
        self::assertSame([], $harness->parts->sent);
    }

    public function testAForwardOnlyRangeShorterThanTheHeadIsSlicedFromIt(): void
    {
        [$reader, $writer] = $this->idleSocket();
        $bytes = random_bytes(5000);
        fwrite($writer, $bytes);
        $source = Source::stream($reader, 5000);

        self::assertSame(substr($bytes, 0, 100), $source->readRange(0, 100));
        self::assertSame(substr($bytes, 100, 200), $source->readRange(100, 200));
        self::assertSame(substr($bytes, 300), $source->readRange(300, 4700));
    }

    public function testAForwardOnlyRangeIsReadChunkByChunkWithATickBetweenChunksOnly(): void
    {
        [$reader, $writer] = $this->idleSocket();
        $bytes = random_bytes(4096 + 3001 + 999);
        fwrite($writer, substr($bytes, 0, 5096));
        // Each tick lets the producer send the next 1000 bytes; the last send holds more than the range.
        $pending = [substr($bytes, 5096, 1000), substr($bytes, 6096, 1000), substr($bytes, 7096)];
        $ticks = 0;
        $tick = static function () use (&$ticks, &$pending, $writer): void {
            $ticks++;
            if ($pending !== []) {
                fwrite($writer, array_shift($pending));
            }
        };
        $source = Source::stream($reader, 4096 + 3001);

        self::assertSame(substr($bytes, 0, 4096), $source->readRange(0, 4096));
        self::assertSame(substr($bytes, 4096, 3001), $source->readRange(4096, 3001, $tick));
        self::assertSame(3, $ticks);
    }

    public function testASeekableStreamThatCannotBeMeasuredIsFileUnreadable(): void
    {
        try {
            Source::stream(ScriptedStream::open('unmeasurable', 'abc', ['seekEnd' => false]), null);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('file_unreadable', $error->errorCode);
            self::assertSame('The stream cannot be read: it cannot be measured.', $error->getMessage());
        }
    }

    public function testASeekableStreamThatCannotBePositionedAtItsEndIsASourceReadFailure(): void
    {
        $source = Source::stream(ScriptedStream::open('no-end', 'abc', ['seekFailsFrom' => 3]), 3);

        try {
            $source->atEnd();
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('source_read_failed', $error->errorCode);
            self::assertSame('The stream could not be read while it was uploading (it cannot be positioned).', $error->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function failingStreams(): iterable
    {
        yield 'a seek that fails' => [['seekFailsFrom' => 100], 100, 'it cannot be positioned'];
        yield 'a read that fails' => [['readFailsFrom' => 0], 0, 'a read failed'];
        yield 'reads that return nothing without the stream ending' => [['endless' => true, 'chunk' => 50], 150, 'a read returned nothing'];
    }

    /** @param array<string, mixed> $script */
    #[DataProvider('failingStreams')]
    public function testASeekableStreamThatFailsMidRangeIsASourceReadFailure(array $script, int $offset, string $reason): void
    {
        $source = Source::stream(ScriptedStream::open('failing', str_repeat('a', 200), $script), 300);

        try {
            $source->readRange($offset, 100);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('source_read_failed', $error->errorCode);
            self::assertSame("The stream could not be read while it was uploading ({$reason}).", $error->getMessage());
            self::assertSame($reason, $error->getPrevious()?->getMessage());
        }
    }

    public function testASeekableStreamThatEndsEarlyIsAStreamSizeMismatch(): void
    {
        $source = Source::stream(ScriptedStream::open('short', 'abc'), 5);

        try {
            $source->readRange(0, 5);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('stream_size_mismatch', $error->errorCode);
        }
    }

    public function testASeekableStreamWhoseHeadCannotBeReadIsFileUnreadable(): void
    {
        $source = Source::stream(ScriptedStream::open('no-head', str_repeat('a', 200), ['readFailsFrom' => 0]), null);

        try {
            $source->head();
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('file_unreadable', $error->errorCode);
            self::assertSame('The stream cannot be read: a read failed.', $error->getMessage());
            self::assertSame('a read failed', $error->getPrevious()?->getMessage());
        }
    }
}
