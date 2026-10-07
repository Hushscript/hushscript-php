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
        }
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
