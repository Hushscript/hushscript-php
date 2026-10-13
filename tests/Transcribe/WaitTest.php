<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\ApiError;
use Hushscript\Exception\JobFailedError;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\UploadError;
use Hushscript\Exception\WaitTimeoutError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Transcribe\Resume;
use Hushscript\Transcribe\Wait;
use PHPUnit\Framework\TestCase;

final class WaitTest extends TestCase
{
    /** @param list<string> $states */
    private function harness(array $states, ?FakeClock $clock = null): PipelineHarness
    {
        $harness = new PipelineHarness($clock ?? new FakeClock());
        $harness->api->on('GET', '/v1/jobs/[^/]+', static function ($request, int $count) use ($states): \GuzzleHttp\Psr7\Response {
            $state = $states[min($count, count($states) - 1)];

            return PipelineHarness::json(200, PipelineHarness::jobBody($state, ['id' => 'job_1', 'error' => $state === 'failed' ? 'bad audio' : null, 'failure_kind' => $state === 'failed' ? 'media_unreadable' : null]));
        });

        return $harness;
    }

    public function testThePollGrowsFromFiveSecondsToAFifteenSecondCeiling(): void
    {
        $harness = $this->harness(['queued', 'processing', 'processing', 'processing', 'processing', 'processing', 'done']);
        $progress = [];

        $job = $harness->jobsWait('job_1', ['on_progress' => static function (array $p) use (&$progress): void {
            $progress[] = $p;
        }]);

        self::assertSame('done', $job->state);
        self::assertSame([5.0, 7.5, 11.25, 15.0, 15.0, 15.0], $harness->clock->sleeps);
        self::assertSame(['queued', 'processing', 'processing', 'processing', 'processing', 'processing'], array_column($progress, 'state'));
        self::assertSame('processing', $progress[0]['stage']);
    }

    public function testAJobStillRunningAtTheTimeoutRaisesWaitTimeoutWithoutAResumeTokenFromJobsWait(): void
    {
        $harness = $this->harness(['processing']);

        try {
            $harness->jobsWait('job_1', ['wait_timeout' => 20]);
            self::fail('expected a WaitTimeoutError');
        } catch (WaitTimeoutError $error) {
            self::assertNull($error->resumeToken);
            self::assertSame('The job was still running after 20 s. It keeps running on the server.', $error->getMessage());
            self::assertSame([5.0, 7.5, 7.5], $harness->clock->sleeps);
            self::assertSame(20.0, $harness->clock->time);
        }
    }

    public function testAFailedJobIsReturnedByWaitAndRaisedWhenItsTranscriptIsWanted(): void
    {
        $harness = $this->harness(['failed']);

        $job = $harness->jobsWait('job_1', []);

        self::assertSame('failed', $job->state);
        self::assertSame('bad audio', $job->error);
        try {
            Wait::transcriptId(['id' => 'job_1', 'state' => 'failed', 'error' => 'bad audio', 'failure_kind' => 'media_unreadable']);
            self::fail('expected a JobFailedError');
        } catch (JobFailedError $error) {
            self::assertSame('job_1', $error->jobId);
            self::assertSame('bad audio', $error->error);
            self::assertSame('media_unreadable', $error->failureKind);
        }
    }

    public function testADoneJobWithoutATranscriptIdIsAnUploadError(): void
    {
        try {
            Wait::transcriptId(['id' => 'job_1', 'state' => 'done']);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('transcript_missing', $error->errorCode);
            self::assertSame('job_1 is done but names no transcript.', $error->getMessage());
        }
    }

    public function testADoneJobWithoutAnIdOrATranscriptIdIsNamedAsTheJob(): void
    {
        try {
            Wait::transcriptId(['state' => 'done', 'transcript_id' => '']);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('transcript_missing', $error->errorCode);
            self::assertSame('The job is done but names no transcript.', $error->getMessage());
        }
    }

    public function testAFractionalTimeoutIsNamedWithoutTrailingZeros(): void
    {
        $harness = $this->harness(['processing']);

        try {
            $harness->jobsWait('job_1', ['wait_timeout' => 0.25]);
            self::fail('expected a WaitTimeoutError');
        } catch (WaitTimeoutError $error) {
            self::assertSame('The job was still running after 0.25 s. It keeps running on the server.', $error->getMessage());
            self::assertSame([0.25], $harness->clock->sleeps);
        }
    }

    public function testWaitHoldsEveryPollToTheWholeJobShape(): void
    {
        $harness = new PipelineHarness();
        $body = PipelineHarness::jobBody('processing');
        unset($body['mode']);
        $harness->api->on('GET', '/v1/jobs/[^/]+', static fn ($request, int $count): \GuzzleHttp\Psr7\Response => PipelineHarness::json(200, $count === 0 ? $body : PipelineHarness::jobBody()));

        try {
            $harness->jobsWait('job_1', []);
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
            self::assertSame([], $harness->clock->sleeps);
            self::assertSame(['GET /v1/jobs/job_1'], $harness->api->lines());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusablePolls(): iterable
    {
        yield 'state not a string' => [['state' => 5]];
        yield 'state missing' => [['state' => null]];
        yield 'transcript id not a string' => [['transcript_id' => 5]];
        yield 'error not a string' => [['state' => 'failed', 'transcript_id' => null, 'error' => 5]];
        yield 'failure kind not a string' => [['state' => 'failed', 'transcript_id' => null, 'failure_kind' => 5]];
        yield 'id not a string' => [['id' => 5]];
    }

    /**
     * The polls after an upload read only the fields they act on, and still refuse those when mistyped.
     *
     * @param  array<string, mixed>  $changes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusablePolls')]
    public function testAPollWithAMistypedFieldItActsOnIsABadResponse(array $changes): void
    {
        $harness = new PipelineHarness();
        $body = array_filter(PipelineHarness::jobBody('done', $changes), static fn (mixed $value, string $key): bool => $value !== null || $key !== 'state', ARRAY_FILTER_USE_BOTH);
        $harness->api->on('GET', '/v1/jobs/[^/]+', PipelineHarness::json(200, $body));

        try {
            $harness->pipeline->resume(self::token($harness), null, ['wait_timeout' => 30]);
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
            self::assertSame(['GET /v1/jobs/job_1'], $harness->api->lines());
        }
    }

    public function testAPollWithoutAnIdOrAJobOnlyFieldIsUsable(): void
    {
        $harness = new PipelineHarness();
        $body = PipelineHarness::jobBody();
        unset($body['id'], $body['mode']);
        $harness->api->on('GET', '/v1/jobs/[^/]+', PipelineHarness::json(200, $body));

        $transcript = $harness->pipeline->resume(self::token($harness), null, []);

        self::assertSame('tr_1', $transcript->id);
        self::assertSame(['GET /v1/jobs/job_1', 'GET /v1/transcripts/tr_1'], $harness->api->lines());
    }

    public function testAJobReadAMomentAgoStandsInForTheFirstPoll(): void
    {
        $harness = $this->harness(['processing', 'done']);

        $transcript = $harness->pipeline->resume(self::token($harness), null, []);

        // The resume read 'processing'; that read is the first poll, so the next one comes after 5 s.
        self::assertSame('tr_1', $transcript->id);
        self::assertSame([5.0], $harness->clock->sleeps);
        self::assertSame(['GET /v1/jobs/job_1', 'GET /v1/jobs/job_1', 'GET /v1/transcripts/tr_1'], $harness->api->lines());
    }

    private static function token(PipelineHarness $harness): string
    {
        return Resume::state(PipelineHarness::JOB, 'key-0123456789abcdef', $harness->size, 20)->encode();
    }

    public function testAJobWithoutAStateIsABadResponse(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/[^/]+', PipelineHarness::json(200, ['id' => 'job_1']));

        try {
            $harness->jobsWait('job_1', []);
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
            self::assertNull($error->numericCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIds')]
    public function testAnUnusableJobIdIsRefusedBeforeAnyRequest(string $id): void
    {
        $harness = new PipelineHarness();

        try {
            $harness->jobsWait($id, []);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
            self::assertSame([], $harness->api->requests);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badServerIds(): iterable
    {
        yield 'dot dot' => ['..'];
        yield 'slash' => ['tr/1'];
        yield 'backslash' => ['tr\\1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badServerIds')]
    public function testATranscriptIdTheServerSendsThatCannotBeAPathSegmentIsABadResponse(string $id): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/[^/]+', PipelineHarness::json(200, PipelineHarness::jobBody('done', ['transcript_id' => $id])));

        try {
            $harness->jobsWait('job_1', []);
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
        }
    }

    public function testAnIdIsPercentEncodedInThePath(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/.+', PipelineHarness::json(200, PipelineHarness::jobBody()));

        $harness->jobsWait('a b?c', []);

        self::assertSame(['GET /v1/jobs/a%20b%3Fc'], $harness->api->lines());
    }
}
