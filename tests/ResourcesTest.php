<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Psr7\Response;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\PreflightError;
use Hushscript\Resource\Account;
use Hushscript\Resource\Jobs;
use Hushscript\Resource\Transcripts;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Tests\Support\Rig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The friendly layer's reads: shapes, dates, ids, pagination (spec 6.3, 6.4). */
final class ResourcesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function row(int $n): array
    {
        return ['id' => sprintf('tr_%03d', $n), 'title' => 'T'.$n, 'language_detected' => null, 'duration_seconds' => 30, 'created_at' => 1760000000 + $n, 'auto_delete_at' => null, 'tags' => ['a']];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function page(array $rows, ?string $next): Response
    {
        return FakeHttp::json(200, ['transcripts' => $rows, 'next_cursor' => $next]);
    }

    public function testBalanceMinutesTruncateTowardZeroAndDatesAreUtc(): void
    {
        $http = new FakeHttp([
            FakeHttp::json(200, ['balance_seconds' => -90, 'held_seconds' => 5, 'credits_expire_at' => 1760000000]),
            FakeHttp::json(200, ['balance_seconds' => 119, 'held_seconds' => 0, 'credits_expire_at' => null, 'purchases_remaining_in_window' => 2, 'x_new' => 1]),
        ]);
        $account = new Account((new Rig($http))->exchange);

        $negative = $account->balance();
        $agent = $account->balance();

        self::assertSame(-90, $negative->seconds);
        self::assertSame(-1, $negative->minutes, 'toward zero, not floor');
        self::assertSame(5, $negative->heldSeconds);
        self::assertNull($negative->purchasesRemaining, 'absent means null');
        self::assertSame('2025-10-09T08:53:20+00:00', $negative->expiresAt?->format('c'));
        self::assertSame('UTC', $negative->expiresAt->getTimezone()->getName());
        self::assertSame(1, $agent->minutes);
        self::assertSame(2, $agent->purchasesRemaining);
        self::assertNull($agent->expiresAt);
    }

    public function testTheAccountCarriesItsLimits(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, [
            'balance_seconds' => 600, 'held_seconds' => 0, 'credits_expire_at' => null, 'card_on_file' => true, 'data_region' => 'eu',
            'limits' => ['max_daily_seconds' => 108000, 'max_active_jobs' => 3, 'active_jobs' => 1, 'used_today_seconds' => 60],
        ])]);

        $info = (new Account((new Rig($http))->exchange))->account();

        self::assertTrue($info->cardOnFile);
        self::assertSame('eu', $info->dataRegion);
        self::assertSame(108000, $info->limits->maxDailySeconds);
        self::assertSame(1, $info->limits->activeJobs);
        self::assertNull($info->purchasesRemainingInWindow);
    }

    public function testAListWalksEveryPageLazilyAndSendsTheCursor(): void
    {
        $http = new FakeHttp([self::page([self::row(1), self::row(2)], 'c2'), self::page([self::row(3)], 'c3'), self::page([self::row(4)], null)]);
        $list = (new Transcripts((new Rig($http))->exchange))->list(['page_size' => 2]);

        self::assertCount(0, $http->requests, 'nothing is sent before the first item is asked for');
        $ids = [];
        foreach ($list as $item) {
            $ids[] = $item->id;
            if ($item->id === 'tr_002') {
                self::assertCount(1, $http->requests, 'the next page is not fetched before it is needed');
            }
        }

        self::assertSame(['tr_001', 'tr_002', 'tr_003', 'tr_004'], $ids);
        self::assertSame(['limit=2', 'cursor=c2&limit=2', 'cursor=c3&limit=2'], array_map(static fn ($r): string => self::sorted($r->getUri()->getQuery()), $http->requests));
    }

    private static function sorted(string $query): string
    {
        $parts = explode('&', $query);
        sort($parts);

        return implode('&', $parts);
    }

    public function testAnEmptyNextCursorEndsTheWalk(): void
    {
        $http = new FakeHttp([self::page([self::row(1)], '')]);

        $items = iterator_to_array((new Transcripts((new Rig($http))->exchange))->list(), false);

        self::assertCount(1, $items);
        self::assertCount(1, $http->requests);
    }

    public function testACursorEqualToTheOneJustSentIsAPaginationLoopAfterItsItemsWereYielded(): void
    {
        $http = new FakeHttp([self::page([self::row(1)], 'same'), self::page([self::row(2)], 'same')]);
        $seen = [];

        try {
            foreach ((new Transcripts((new Rig($http))->exchange))->list() as $item) {
                $seen[] = $item->id;
            }
            self::fail('expected a pagination_loop');
        } catch (HushscriptException $error) {
            self::assertSame('pagination_loop', $error->errorCode);
        }

        self::assertSame(['tr_001', 'tr_002'], $seen);
        self::assertCount(2, $http->requests);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badOptions(): iterable
    {
        yield 'zero' => [['page_size' => 0]];
        yield 'negative' => [['page_size' => -1]];
        yield 'float' => [['page_size' => 1.5]];
        yield 'string' => [['page_size' => '5']];
        yield 'unknown' => [['limit' => 5]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badOptions')]
    public function testABadPageSizeIsRefusedWhenTheListIsCalledNotWhenItIsIterated(array $options): void
    {
        $http = new FakeHttp();

        try {
            (new Transcripts((new Rig($http))->exchange))->list($options); // @phpstan-ignore argument.type
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
        }

        self::assertCount(0, $http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
        yield 'invalid utf-8' => ["tr_\xB1"];
        yield 'slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
    }

    #[DataProvider('badIds')]
    public function testAnUnusableIdIsRefusedBeforeAnyRequest(string $id): void
    {
        $http = new FakeHttp();
        $transcripts = new Transcripts((new Rig($http))->exchange);

        try {
            $transcripts->get($id);
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
        }

        self::assertCount(0, $http->requests);
    }

    public function testAnIdIsOneEncodedPathSegment(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, PipelineHarness::transcriptBody('a b?c'))]);

        $transcript = (new Transcripts((new Rig($http))->exchange))->get('a b?c');

        self::assertSame('/v1/transcripts/a%20b%3Fc', $http->requests[0]->getUri()->getPath());
        self::assertSame(20, $transcript->durationSeconds);
        self::assertSame('2025-10-09T08:53:20+00:00', $transcript->createdAt->format('c'));
        self::assertSame(['A' => 'Dana'], $transcript->speakers);
    }

    public function testADeletionRecordKeepsItsReceiptAsTheApiSentIt(): void
    {
        $receipt = ['deleted_at' => '2026-01-01T00:00:00Z', 'nested' => ['k' => 1]];
        $http = new FakeHttp([FakeHttp::json(200, ['deleted' => [
            ['id' => 'tr_1', 'title' => 'x', 'duration_seconds' => 5, 'created_at' => 1760000000, 'deleted_at' => 1760000100, 'cause' => 'future_cause', 'receipt' => $receipt],
            ['id' => 'tr_2', 'title' => 'y', 'duration_seconds' => 6, 'created_at' => 1760000000, 'deleted_at' => 1760000100, 'cause' => 'manual', 'receipt' => null],
        ], 'next_cursor' => null])]);

        $records = iterator_to_array((new Transcripts((new Rig($http))->exchange))->deleted(), false);

        self::assertSame($receipt, $records[0]->receipt);
        self::assertSame('future_cause', $records[0]->cause, 'an unknown cause passes through');
        self::assertNull($records[1]->receipt);
        self::assertSame(1760000100, $records[1]->deletedAt->getTimestamp());
    }

    public function testAnyTwoHundredSomethingWithAUsableBodySucceedsAndABadOneCarriesItsStatus(): void
    {
        $http = new FakeHttp([
            new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(['balance_seconds' => 60, 'held_seconds' => 0])),
            new Response(202, ['Content-Type' => 'application/json'], '{"balance_seconds":"x","held_seconds":0}'),
        ]);
        $account = new Account((new Rig($http))->exchange);

        self::assertSame(1, $account->balance()->minutes);
        try {
            $account->balance();
            self::fail('expected bad_response');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(202, $error->status);
            self::assertNull($error->numericCode);
            self::assertStringNotContainsString('"x"', $error->getMessage(), 'a value is never echoed');
        }
    }

    public function testJobsListSendsActiveAndWalksThePages(): void
    {
        $jobs = [PipelineHarness::jobBody('processing'), PipelineHarness::jobBody('queued', ['id' => 'job_2'])];
        $http = new FakeHttp([
            FakeHttp::json(200, ['jobs' => [$jobs[0]], 'next_cursor' => 'c2']),
            FakeHttp::json(200, ['jobs' => [$jobs[1]], 'next_cursor' => '']),
        ]);

        $listed = iterator_to_array((new Jobs((new Rig($http))->exchange, (new PipelineHarness())->pipeline))->list(['active' => true]), false);

        self::assertSame(['job_1', 'job_2'], array_map(static fn ($job): string => $job->id, $listed));
        self::assertSame('active=true', self::sorted($http->requests[0]->getUri()->getQuery()));
        self::assertSame('active=true&cursor=c2', self::sorted($http->requests[1]->getUri()->getQuery()));
    }

    public function testJobsListRefusesAnUnknownOptionAtTheCall(): void
    {
        $this->expectException(PreflightError::class);

        (new Jobs((new Rig(new FakeHttp([])))->exchange, (new PipelineHarness())->pipeline))->list(['actve' => true]);
    }

    public function testJobsGetReturnsTheFullShapeAndPassesAnUnknownStateThrough(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, PipelineHarness::jobBody('reticulating', ['transcript_id' => null, 'private_result_key' => 'x', 'fetch_url' => 'https://secret.example/']))]);

        $job = (new Jobs((new Rig($http))->exchange, (new PipelineHarness())->pipeline))->get('job_1');

        self::assertSame('reticulating', $job->state);
        self::assertSame('standard', $job->mode);
        self::assertSame(1760000000, $job->createdAt->getTimestamp());
        self::assertStringNotContainsString('secret.example', print_r($job, true));
        self::assertStringNotContainsString('private_result', print_r($job, true));
    }

    public function testJobsGetRefusesAnUnusableIdBeforeAnyRequestAndAJobWithoutAStateIsBadResponse(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['id' => 'job_1'])]);
        $jobs = new Jobs((new Rig($http))->exchange, (new PipelineHarness())->pipeline);

        try {
            $jobs->get('..');
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
            self::assertCount(0, $http->requests);
        }
        try {
            $jobs->get('job_1');
            self::fail('expected bad_response');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
        }
    }

    public function testJobsWaitReturnsAFailedJobInsteadOfRaising(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, PipelineHarness::jobBody('failed', ['error' => 'undecodable', 'failure_kind' => 'media_undecodable'])));

        $job = $harness->jobsWait('job_1', []);

        self::assertSame('failed', $job->state);
        self::assertSame('media_undecodable', $job->failureKind);
    }

    public function testJobsWaitReturnsADoneJob(): void
    {
        $harness = new PipelineHarness();

        $job = $harness->jobsWait('job_1', []);

        self::assertSame('done', $job->state);
        self::assertSame('tr_1', $job->transcriptId);
    }
}
