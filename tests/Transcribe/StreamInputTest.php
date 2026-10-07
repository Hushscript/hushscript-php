<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use GuzzleHttp\Psr7\Response;
use Hushscript\Client;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\ResumeMismatchError;
use Hushscript\Exception\UnsupportedMediaError;
use Hushscript\Exception\UploadError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Tests\Support\RecordingParts;
use Hushscript\Tests\Support\Tokens;
use Hushscript\Transcribe\Probe;
use Hushscript\Transcribe\Resume;
use Hushscript\Transcribe\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A stream as the input of transcribe and resume: one that can seek and one that cannot (spec 6.1, 7.3, 8.1, 8.2). */
final class StreamInputTest extends TestCase
{
    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
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
        $handle = popen('cat '.escapeshellarg($path), 'r');
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
        } catch (HushscriptException $error) {
            self::assertNotNull($error->idempotencyKey);
        }
        self::assertTrue(is_resource($stream));
        self::assertSame([], $parts->requests);
    }
}
