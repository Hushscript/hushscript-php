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

        self::assertSame('tr_1', $transcript['id']);
        self::assertCount(3, $harness->parts->sent);
        self::assertSame([], array_filter($harness->api->lines(), static fn (string $line): bool => str_starts_with($line, 'PUT /hushscript/')));
    }

    public function testWithoutAPartTransportACustomClientSendsThePartsOneAtATimeWithoutAuthorization(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('PUT', '/hushscript/job_1/\d+', PipelineHarness::stored());
        $client = self::client($harness, false);

        $transcript = $client->transcribe($harness->path());

        self::assertSame('tr_1', $transcript['id']);
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

        self::assertSame('tr_1', $transcript['id']);
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

        self::assertSame('done', $job['state']);
        self::assertSame('tr_1', $job['transcript_id']);
    }

    public function testAPartTransportThatIsNotOneIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationError::class);

        new Client(Tokens::TEST, ['part_transport' => new \stdClass()]);
    }
}
