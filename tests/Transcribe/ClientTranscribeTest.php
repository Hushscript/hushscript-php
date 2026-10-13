<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Client;
use Hushscript\Exception\ConfigurationError;
use Hushscript\Exception\ResumeMismatchError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Tests\Support\Tokens;
use Hushscript\Transcribe\Resume;
use PHPUnit\Framework\TestCase;

/** The public entry points wire the pipeline to the client's own transport choices. */
final class ClientTranscribeTest extends TestCase
{
    private static function client(PipelineHarness $harness, bool $ownTransport): Client
    {
        $options = ['base_url' => PipelineHarness::BASE, 'clock' => new FakeClock(), 'http_client' => $harness->api];
        if ($ownTransport) {
            $options['part_transport'] = $harness->parts;
        }

        return new Client(Tokens::TEST, $options);
    }

    public function testAPartTransportOptionCarriesThePartsAndTheApiClientNeverSeesThem(): void
    {
        $harness = new PipelineHarness();
        $client = self::client($harness, true);

        $transcript = $client->transcribe($harness->path());

        self::assertSame('tr_1', $transcript->id);
        self::assertCount(3, $harness->parts->sent);
        self::assertSame([], array_filter($harness->api->lines(), static fn (string $line): bool => str_starts_with($line, 'PUT /hushscript/')));
    }

    public function testWithoutAPartTransportACustomClientSendsThePartsOneAtATimeWithoutAuthorization(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('PUT', '/hushscript/job_1/\d+', PipelineHarness::stored());
        $client = self::client($harness, false);

        $transcript = $client->transcribe($harness->path());

        self::assertSame('tr_1', $transcript->id);
        $parts = $harness->api->to('PUT', '/hushscript/job_1/\d+');
        self::assertCount(3, $parts);
        foreach ($parts as $request) {
            self::assertSame('', $request->getHeaderLine('Authorization'), 'a signed URL is its own credential');
        }
        self::assertSame([], $harness->parts->sent);
    }

    public function testResumeTakesTheOptionsInPlaceOfAFile(): void
    {
        $harness = new PipelineHarness();
        $client = self::client($harness, true);
        $token = Resume::state(PipelineHarness::JOB, 'key-1', $harness->size, 20)->encode();

        $transcript = $client->resume($token, ['wait_timeout' => 30]);

        self::assertSame('tr_1', $transcript->id);
    }

    public function testResumeTakesAFilePathAndTheJobsWaitFinishesAJob(): void
    {
        $harness = new PipelineHarness();
        $client = self::client($harness, true);
        $token = Resume::state(PipelineHarness::JOB, 'key-1', $harness->size + 1, 20)->encode();

        try {
            $client->resume($token, $harness->path());
            self::fail('expected a mismatch');
        } catch (ResumeMismatchError $error) {
            self::assertSame('resume_file_mismatch', $error->errorCode);
        }

        $job = $client->jobs()->wait(PipelineHarness::JOB);

        self::assertSame('done', $job->state);
        self::assertSame('tr_1', $job->transcriptId);
    }

    public function testAPartTransportThatIsNotOneIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationError::class);

        new Client(Tokens::TEST, ['part_transport' => new \stdClass()]);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidPublicOptions(): iterable
    {
        foreach (OptionsTest::invalid() as $name => [$options]) {
            yield 'transcribe '.$name => ['transcribe', $options];
        }
        foreach (OptionsTest::invalidOtherOptions() as $name => [$operation, $options]) {
            yield $name => [$operation, $options];
        }
    }

    /** @param array<string, mixed> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPublicOptions')]
    public function testPublicEntryPointsRejectOptionsBeforeRequests(string $operation, array $options): void
    {
        $harness = new PipelineHarness();
        $client = self::client($harness, true);
        try {
            if ($operation === 'transcribe') {
                $client->transcribe($harness->path(), $options);
            } elseif ($operation === 'resume') {
                $token = Resume::state(PipelineHarness::JOB, 'key-1', $harness->size, 20)->encode();
                $client->resume($token, null, $options);
            } else {
                $client->jobs()->wait('job_1', $options);
            }
            self::fail('expected invalid_option');
        } catch (\Hushscript\Exception\PreflightError $error) {
            self::assertSame(\Hushscript\Exception\PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame([], $harness->api->requests);
        self::assertSame([], $harness->parts->sent);
    }

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function publicCallbacks(): iterable
    {
        foreach (['domain', 'sdk'] as $kind) {
            yield 'transcribe progress '.$kind => ['transcribe', 'on_progress', $kind, ['GET /v1/account']];
            yield 'transcribe token '.$kind => ['transcribe', 'on_resume_token', $kind, ['GET /v1/account', 'POST /v1/uploads']];
            yield 'resume progress '.$kind => ['resume', 'on_progress', $kind, ['GET /v1/jobs/job_1']];
            yield 'wait progress '.$kind => ['wait', 'on_progress', $kind, ['GET /v1/jobs/job_1']];
        }
    }

    /** @param list<string> $requests */
    #[\PHPUnit\Framework\Attributes\DataProvider('publicCallbacks')]
    public function testPublicCallbacksStopAtTheSameThrownObject(string $operation, string $callback, string $kind, array $requests): void
    {
        $harness = new PipelineHarness();
        $client = self::client($harness, true);
        $mine = $kind === 'sdk' ? new \Hushscript\Exception\ConflictError('mine', 'wrong_state') : new \DomainException('caller error');
        $options = [$callback => static function () use ($mine): never {
            throw $mine;
        }];
        if ($operation === 'resume') {
            $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, PipelineHarness::jobBody('uploading')));
        } elseif ($operation === 'wait') {
            $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, PipelineHarness::jobBody('processing')));
        }
        try {
            if ($operation === 'transcribe') {
                $client->transcribe($harness->path(), $options);
            } elseif ($operation === 'resume') {
                $token = Resume::state(PipelineHarness::JOB, 'key-1', $harness->size, 20)->encode();
                $client->resume($token, $harness->path(), $options);
            } else {
                $client->jobs()->wait('job_1', $options);
            }
            self::fail('expected callback error');
        } catch (\Throwable $error) {
            self::assertSame($mine, $error);
            if ($mine instanceof \Hushscript\Exception\HushscriptException) {
                self::assertNull($mine->idempotencyKey);
                self::assertNull($mine->resumeToken);
                self::assertNull($mine->status);
                self::assertSame('wrong_state', $mine->errorCode);
            }
        }
        self::assertSame($requests, $harness->api->lines());
        self::assertSame([], $harness->parts->sent);
    }

    /** @return iterable<string, array{float|int, ?string, ?string}> */
    public static function publicDurations(): iterable
    {
        yield from PipelineTest::durations();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicDurations')]
    public function testClientTranscribeChecksDurationBeforeRequests(float|int $seconds, ?string $class, ?string $code): void
    {
        $harness = new PipelineHarness();
        $client = self::client($harness, true);
        if ($class === null) {
            $client->transcribe($harness->path(), ['duration_seconds' => $seconds]);
            $creates = $harness->api->to('POST', '/v1/uploads');
            self::assertCount(1, $creates);
            $body = json_decode((string) $creates[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame($seconds, $body['duration_seconds']);
        } else {
            try {
                $client->transcribe($harness->path(), ['duration_seconds' => $seconds]);
                self::fail('expected duration error');
            } catch (\Hushscript\Exception\HushscriptException $error) {
                self::assertSame($class, $error::class);
                self::assertSame($code, $error->errorCode);
                self::assertNull($error->status);
            }
            self::assertSame([], $harness->api->requests);
            self::assertSame([], $harness->parts->sent);
        }
    }
}
