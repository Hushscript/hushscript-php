<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\ResumeMismatchError;
use Hushscript\Exception\WaitTimeoutError;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Transcribe\Resume;
use PHPUnit\Framework\TestCase;

final class ResumePipelineTest extends TestCase
{
    private static function token(PipelineHarness $harness, ?int $size = null): string
    {
        return Resume::state(PipelineHarness::JOB, 'key-1', $size ?? $harness->size, 20)->encode();
    }

    private static function jobIs(PipelineHarness $harness, string $state): void
    {
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, ['id' => 'job_1', 'state' => $state, 'transcript_id' => 'tr_1']));
    }

    /** @param  array<string, mixed>  $options */
    private static function failure(PipelineHarness $harness, string $token, ?string $path, array $options = []): ResumeMismatchError
    {
        try {
            $harness->pipeline->resume($token, $path, $options);
        } catch (ResumeMismatchError $error) {
            return $error;
        }
        self::fail('expected a resume mismatch');
    }

    public function testAJobPastUploadingFinishesWithoutAFileAndWithoutReplayingTheCreate(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/job_1', [
            PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'processing']),
            PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'done', 'transcript_id' => 'tr_1']),
        ]);

        $transcript = $harness->pipeline->resume(self::token($harness), null, []);

        self::assertSame('tr_1', $transcript->id);
        self::assertSame([], $harness->api->to('POST', '/v1/uploads'));
        self::assertSame([], $harness->parts->sent);
    }

    public function testAnUploadingJobReplaysTheCreateWithTheSameKeyAndFinishes(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/job_1', [
            PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'uploading']),
            PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'done', 'transcript_id' => 'tr_1']),
        ]);
        $seen = [];

        $transcript = $harness->pipeline->resume(self::token($harness), $harness->path(), [
            'on_progress' => static function (array $progress) use (&$seen): void {
                $seen[] = $progress;
            },
        ]);

        self::assertSame('tr_1', $transcript->id);
        $creates = $harness->api->to('POST', '/v1/uploads');
        self::assertCount(1, $creates);
        $body = json_decode((string) $creates[0]->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('key-1', $body['idempotency_key']);
        self::assertSame($harness->size, $body['size_bytes']);
        self::assertSame(20, $body['duration_seconds']);
        self::assertSame('GET /v1/jobs/job_1', $harness->api->lines()[0], 'the job is read before anything is replayed');
        self::assertSame(0, $seen[0]['percent']);
        self::assertSame('uploading', $seen[0]['stage']);
        self::assertSame(0, $seen[0]['bytes_sent']);
        self::assertSame($harness->size, $seen[0]['bytes_total']);
    }

    public function testAMissingJobIsResumeJobMissingAndNothingIsReplayed(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(404, ['error' => 'not_found', 'code' => 4040]));

        $error = self::failure($harness, self::token($harness), $harness->path());

        self::assertSame('resume_job_missing', $error->errorCode);
        self::assertSame([], $harness->api->to('POST', '/v1/uploads'));
        self::assertNotNull($error->getPrevious(), 'the 404 is kept as the cause');
        self::assertSame('key-1', $error->idempotencyKey);
    }

    public function testAReplayThatReturnsAnotherJobIsResumeJobMissing(): void
    {
        $harness = new PipelineHarness();
        self::jobIs($harness, 'uploading');
        $other = $harness->createBody();
        $other['job_id'] = 'job_other';
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $other));

        try {
            $harness->pipeline->resume(self::token($harness), $harness->path(), []);
            self::fail('expected a resume mismatch');
        } catch (HushscriptException $error) {
            self::assertSame('resume_job_missing', $error->errorCode);
            self::assertSame(ResumeMismatchError::class, $error::class);
            self::assertNull($error->status);
        }
        self::assertSame(['GET /v1/jobs/job_1', 'POST /v1/uploads'], $harness->api->lines());
        self::assertSame([], $harness->parts->sent, 'nothing is uploaded to the other job');
    }

    public function testADifferentSizeIsRefusedBeforeAnyRequest(): void
    {
        $harness = new PipelineHarness();

        $error = self::failure($harness, self::token($harness, $harness->size + 1), $harness->path());

        self::assertSame('resume_file_mismatch', $error->errorCode);
        self::assertSame([], $harness->api->requests);
    }

    public function testAnUploadingJobWithoutAFileNeedsOne(): void
    {
        $harness = new PipelineHarness();
        self::jobIs($harness, 'uploading');

        $error = self::failure($harness, self::token($harness), null);

        self::assertSame('resume_file_required', $error->errorCode);
        self::assertSame([], $harness->api->to('POST', '/v1/uploads'));
    }

    public function testThePathIsOnlyOpenedOnceTheJobIsUploading(): void
    {
        $harness = new PipelineHarness();
        self::jobIs($harness, 'done');

        $transcript = $harness->pipeline->resume(self::token($harness), '/nonexistent/never-opened.mp3', []);

        self::assertSame('tr_1', $transcript->id);
    }

    public function testAnUnreadablePathOfAnUploadingJobIsFileUnreadable(): void
    {
        $harness = new PipelineHarness();
        self::jobIs($harness, 'uploading');

        try {
            $harness->pipeline->resume(self::token($harness), '/nonexistent/never-opened.mp3', []);
            self::fail('expected file_unreadable');
        } catch (HushscriptException $error) {
            self::assertSame('file_unreadable', $error->errorCode);
            self::assertSame('key-1', $error->idempotencyKey);
            self::assertNotNull($error->resumeToken);
        }
    }

    public function testATimeoutWhileWaitingCarriesTheSameTokenToResumeAgain(): void
    {
        $harness = new PipelineHarness();
        self::jobIs($harness, 'processing');
        $token = self::token($harness);

        try {
            $harness->pipeline->resume($token, null, ['wait_timeout' => 10]);
            self::fail('expected a timeout');
        } catch (WaitTimeoutError $error) {
            self::assertSame($token, $error->resumeToken);
            self::assertSame('key-1', $error->idempotencyKey);
        }
    }

    public function testAMalformedTokenSendsNothing(): void
    {
        $harness = new PipelineHarness();

        try {
            $harness->pipeline->resume('not a token', null, []);
            self::fail('expected a configuration error');
        } catch (HushscriptException $error) {
            self::assertSame(\Hushscript\Exception\ConfigurationError::class, $error::class);
            self::assertSame('config_invalid', $error->errorCode);
            self::assertNull($error->status);
            self::assertSame([], $harness->api->requests);
        }
    }

    public function testAFileThatChangesSizeWhileTheJobIsReadIsRefusedBeforeTheCreateIsReplayed(): void
    {
        $harness = new PipelineHarness();
        $copy = tempnam(sys_get_temp_dir(), 'hs-resume');
        self::assertIsString($copy);
        copy($harness->path(), $copy);
        $harness->api->on('GET', '/v1/jobs/job_1', static function () use ($copy): \GuzzleHttp\Psr7\Response {
            file_put_contents($copy, 'abc', FILE_APPEND);

            return PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'uploading', 'transcript_id' => null]);
        });

        try {
            $error = self::failure($harness, self::token($harness), $copy);
        } finally {
            unlink($copy);
        }

        self::assertSame('resume_file_mismatch', $error->errorCode);
        self::assertSame('The file is '.($harness->size + 3).' bytes; the upload started with '.$harness->size.'.', $error->getMessage());
        self::assertSame(['GET /v1/jobs/job_1'], $harness->api->lines());
    }

    public function testAOneByteFileReportsTheWholeUploadOnceItsPartIsSent(): void
    {
        $harness = new PipelineHarness();
        $copy = tempnam(sys_get_temp_dir(), 'hs-resume');
        self::assertIsString($copy);
        file_put_contents($copy, 'x');
        self::jobIs($harness, 'uploading');
        $harness->api->on('GET', '/v1/jobs/job_1', [
            PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'uploading', 'transcript_id' => null]),
            PipelineHarness::json(200, PipelineHarness::jobBody()),
        ]);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody(proxied: true)));
        $seen = [];

        try {
            $harness->pipeline->resume(self::token($harness, 1), $copy, ['on_progress' => static function (array $event) use (&$seen): void {
                $seen[] = $event;
            }]);
        } finally {
            unlink($copy);
        }

        $uploading = array_values(array_filter($seen, static fn (array $event): bool => $event['stage'] === 'uploading'));
        self::assertSame([[0, 0], [100, 1]], array_map(static fn (array $event): array => [$event['percent'], $event['bytes_sent']], $uploading));
    }
}
