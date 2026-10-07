<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use GuzzleHttp\Psr7\Response;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\UploadError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeIdleClock;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Transcribe\Heartbeat;
use Hushscript\Transcribe\SyncTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Hushscript\Transcript;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** How the parts of one job go: the heartbeat while waiting, the part-urls failures, the 403 refresh (spec 8.4, 8.5). */
final class UploadRulesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $options
     */
    private static function go(PipelineHarness $harness, array $options = []): Transcript
    {
        return $harness->pipeline->transcribe($harness->path(), $options);
    }

    private static function failure(PipelineHarness $harness, string $class = HushscriptException::class): HushscriptException
    {
        try {
            self::go($harness);
        } catch (HushscriptException $error) {
            self::assertInstanceOf($class, $error); // @phpstan-ignore argument.type

            return $error;
        }
        self::fail('expected '.$class);
    }

    private static function partNumber(string $url): int
    {
        preg_match('~/(\d+)(?:\?|$)~', $url, $match);

        return (int) ($match[1] ?? 0);
    }

    private static function page(PipelineHarness $harness, int $from, int $to, ?int $next = null): Response
    {
        $urls = [];
        for ($n = $from; $n <= $to; $n++) {
            $urls[] = ['n' => $n, 'url' => PipelineHarness::url($n, '&fresh=1')];
        }

        return PipelineHarness::json(200, ['part_size_bytes' => $harness->partSize, 'total_parts' => $harness->totalParts, 'part_urls' => $urls, 'next_part_number' => $next]);
    }

    /** The create answers with a plan that lists no URLs yet: the first part has to fetch a page. */
    private static function planWithoutUrls(PipelineHarness $harness): void
    {
        $body = $harness->createBody();
        self::assertIsArray($body['upload']);
        $body['upload']['part_urls'] = [];
        $body['upload']['next_part_number'] = 1;
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $body));
    }

    /**
     * @param  list<float>  $beats
     */
    private static function longestGap(array $beats, float $end): float
    {
        $gap = 0.0;
        $last = 0.0;
        foreach ([...$beats, $end] as $time) {
            $gap = max($gap, $time - $last);
            $last = $time;
        }

        return $gap;
    }

    public function testAClientThatBlocksPerPartStillGetsAHeartbeatBetweenEveryPart(): void
    {
        $clock = new FakeClock();
        $client = new class ($clock) implements ClientInterface {
            public function __construct(private readonly FakeClock $clock)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->clock->time += 50.0;

                return PipelineHarness::stored();
            }
        };
        $harness = new PipelineHarness(clock: $clock, totalParts: 6, transport: new SyncTransport($client));
        $beats = [];
        $completedAt = null;
        $harness->api->on('POST', '/v1/uploads/[^/]+/heartbeat', static function () use ($clock, &$beats): Response {
            $beats[] = $clock->time;

            return PipelineHarness::json(200, ['ok' => true]);
        });
        $harness->api->on('POST', '/v1/uploads/[^/]+/complete', static function () use ($clock, &$completedAt): Response {
            $completedAt = $clock->time;

            return PipelineHarness::json(200, ['ok' => true, 'state' => 'queued']);
        });

        self::go($harness, ['concurrency' => 4]);

        self::assertNotNull($completedAt);
        self::assertGreaterThanOrEqual(300.0, $completedAt, 'six parts of 50 s');
        self::assertLessThanOrEqual(120.0, self::longestGap($beats, $completedAt), 'a ping at least every 120 s although each request blocks');
    }

    public function testARetryAfterDuringTheUploadDoesNotStopTheHeartbeat(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, totalParts: 20, script: static fn (array $request): array => [PipelineHarness::stored('"e'.self::partNumber($request['url']).'"'), 25.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', [
            new Response(429, ['Retry-After' => '60', 'Content-Type' => 'application/json'], '{"error":"rate_limited","code":1100}'),
            self::page($harness, 17, 20),
        ]);
        $beats = [];
        $completedAt = null;
        $harness->api->on('POST', '/v1/uploads/[^/]+/heartbeat', static function () use ($clock, &$beats): Response {
            $beats[] = $clock->time;

            return PipelineHarness::json(200, ['ok' => true]);
        });
        $harness->api->on('POST', '/v1/uploads/[^/]+/complete', static function () use ($clock, &$completedAt): Response {
            $completedAt = $clock->time;

            return PipelineHarness::json(200, ['ok' => true, 'state' => 'queued']);
        });
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));

        self::go($harness);

        self::assertCount(2, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'), 'the 429 was retried after its Retry-After');
        self::assertGreaterThanOrEqual(60.0, array_sum($clock->sleeps), 'the wait was a minute, taken in slices');
        self::assertNotNull($completedAt);
        self::assertLessThanOrEqual(120.0, self::longestGap($beats, $completedAt));
    }

    public function testPingsAnsweredWithARetryAfterNeverStartAnotherPingFromInsideTheirOwnWait(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, totalParts: 20, script: static fn (array $request): array => [PipelineHarness::stored('"e'.self::partNumber($request['url']).'"'), 25.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', self::page($harness, 17, 20));
        $depth = 0;
        $hits = 0;
        $harness->api->on('POST', '/v1/uploads/[^/]+/heartbeat', static function () use (&$depth, &$hits): Response {
            $hits++;
            $beats = count(array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), static fn (array $frame): bool => ($frame['class'] ?? '') === Heartbeat::class && $frame['function'] === 'beat'));
            $depth = max($depth, $beats);

            return new Response(429, ['Retry-After' => '60', 'Content-Type' => 'application/json'], '{"error":"rate_limited","code":5000}');
        });

        self::go($harness);

        self::assertSame(1, $depth, 'a ping that waits for its own retry is never interrupted by another ping');
        self::assertLessThanOrEqual(24, $hits, 'bounded: four attempts per ping and a ping per 120 s at the most');
        self::assertCount(1, $harness->api->to('POST', '/v1/uploads/[^/]+/complete'), 'the upload goes on and completes');
    }

    public function testAfterOneLongBlockThePingsKeepTheIntervalInsteadOfOnePerPart(): void
    {
        $clock = new FakeClock();
        $client = new class ($clock) implements ClientInterface {
            private int $calls = 0;

            public function __construct(private readonly FakeClock $clock)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->clock->time += $this->calls++ === 0 ? 250.0 : 1.0;

                return PipelineHarness::stored();
            }
        };
        $harness = new PipelineHarness(clock: $clock, totalParts: 12, transport: new SyncTransport($client));

        self::go($harness, ['concurrency' => 1]);

        self::assertLessThanOrEqual(3, count($harness->api->to('POST', '/v1/uploads/[^/]+/heartbeat')), 'the long first part is followed by one ping, not one per fast part');
    }

    public function testADirectPutThatIsForbiddenAfterASuccessGetsOneFreshPageNotTwo(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [self::partNumber($request['url']) === 1 ? PipelineHarness::stored() : new Response(403), 0.0]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', self::page($harness, 1, 3));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('parts_exhausted', $error->errorCode);
        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'));
    }

    public function testADirectPutThatIsForbiddenBeforeAnySuccessGetsOneFreshPageThenTheApi(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [str_contains($request['url'], 'r2.example') ? new Response(403) : PipelineHarness::stored(), 0.0]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', self::page($harness, 1, 3));

        self::go($harness);

        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'));
        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], array_values(array_filter($harness->parts->lines(), static fn (string $line): bool => str_contains($line, 'api'))));
    }

    public function testAPartUrlsFailureBeforeAnyDirectSuccessSendsTheUploadThroughTheApi(): void
    {
        $harness = new PipelineHarness();
        self::planWithoutUrls($harness);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', new Response(500));

        self::go($harness);

        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], $harness->parts->lines());
        self::assertSame(0, $harness->api->to('POST', '/v1/uploads/job_1/complete')[0]->getBody()->getSize(), 'a proxied upload completes without a parts body');
    }

    public function testAPartUrlsFailureAfterADirectSuccessIsPartsExhaustedWithTheFailureAsItsCause(): void
    {
        $harness = new PipelineHarness(totalParts: 20);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', new Response(500));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('parts_exhausted', $error->errorCode);
        self::assertInstanceOf(ApiError::class, $error->getPrevious());
        self::assertSame(500, $error->getPrevious()->status);
    }

    public function testATerminalPartUrlsRefusalAfterADirectSuccessSurfacesAsItself(): void
    {
        $harness = new PipelineHarness(totalParts: 20);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(404, ['error' => 'not_found', 'code' => 1004]));

        $error = self::failure($harness, ApiError::class);

        self::assertSame(404, $error->status);
        self::assertNotInstanceOf(UploadError::class, $error);
    }

    /** @return iterable<string, array{Response}> */
    public static function pagesWithoutUrls(): iterable
    {
        yield 'a null page' => [new Response(200, ['Content-Type' => 'application/json'], 'null')];
        yield 'an empty page' => [PipelineHarness::json(200, ['part_size_bytes' => 1, 'total_parts' => 3, 'part_urls' => [], 'next_part_number' => null])];
    }

    #[DataProvider('pagesWithoutUrls')]
    public function testAPageWithoutUrlsMeansTheApiTakesTheParts(Response $page): void
    {
        $harness = new PipelineHarness();
        self::planWithoutUrls($harness);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', $page);

        self::go($harness);

        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], $harness->parts->lines());
        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'), 'the answer is not asked for again');
    }

    public function testANullPageAfterADirectSuccessIsPartUrlMissing(): void
    {
        $harness = new PipelineHarness(totalParts: 20);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', new Response(200, ['Content-Type' => 'application/json'], 'null'));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('part_url_missing', $error->errorCode);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableUrls(): iterable
    {
        yield 'a port out of range' => ['https://r2.example.com:99999999/hushscript/job_1/1?X-Amz-Signature=SECRET'];
        yield 'no host' => ['/hushscript/job_1/1?X-Amz-Signature=SECRET'];
        yield 'not http' => ['ftp://r2.example.com/hushscript/job_1/1?X-Amz-Signature=SECRET'];
    }

    #[DataProvider('unusableUrls')]
    public function testAnUnusableSignedUrlIsBadResponseWithTheKeyAndTokenAndWithoutTheUrl(string $url): void
    {
        $harness = new PipelineHarness();
        $body = $harness->createBody();
        self::assertIsArray($body['upload']);
        $body['upload']['part_urls'] = [['n' => 1, 'url' => $url]];
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $body));

        $error = self::failure($harness, ApiError::class);

        self::assertSame('bad_response', $error->errorCode);
        self::assertNotNull($error->idempotencyKey);
        self::assertNotNull($error->resumeToken);
        $surface = $error->getMessage();
        for ($link = $error; $link !== null; $link = $link->getPrevious()) {
            $surface .= $link->getMessage().$link->getTraceAsString();
            foreach ($link->getTrace() as $frame) {
                foreach (str_contains($frame['file'] ?? '', dirname(__DIR__, 2).'/src/') ? ($frame['args'] ?? []) : [] as $argument) {
                    $surface .= $argument instanceof \Closure ? '' : print_r($argument, true);
                }
            }
        }
        self::assertStringNotContainsString('X-Amz-Signature', $surface);
        self::assertStringNotContainsString('SECRET', $surface);
        self::assertSame([], $harness->parts->sent);
    }

    public function testADirectSuccessWithoutAnEtagThatArrivesAfterTheSwitchToTheApiIsIgnored(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, script: static function (array $request): array {
            if (str_contains($request['url'], 'r2.example')) {
                return self::partNumber($request['url']) === 1 ? [new Response(500), 0.0] : [new Response(200), 1000.0];
            }

            return [PipelineHarness::stored(), 0.0];
        });
        $clock->nextEvent = $harness->parts->nextRelease(...);

        self::go($harness);

        self::assertContains('PUT api:2', $harness->parts->lines(), 'part 2 is sent through the API instead of failing on the missing ETag');
        self::assertSame(0, $harness->api->to('POST', '/v1/uploads/job_1/complete')[0]->getBody()->getSize());
    }

    public function testADirectSuccessWithoutAnEtagBeforeAnySwitchIsEtagMissing(): void
    {
        $harness = new PipelineHarness(script: static fn (): array => [new Response(200), 0.0]);

        self::assertSame('etag_missing', self::failure($harness, UploadError::class)->errorCode);
    }
}
