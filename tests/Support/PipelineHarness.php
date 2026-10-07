<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Hushscript\Credentials;
use Hushscript\Http\Exchange;
use Hushscript\Http\RetryMiddleware;
use Hushscript\Http\WaitTicker;
use Hushscript\Log;
use Hushscript\Transcribe\PartFailure;
use Hushscript\Transcribe\PartTransport;
use Hushscript\Transcribe\Pipeline;

/**
 * A pipeline over a scripted API and scripted part transport, on a fake clock, with a real media
 * file from the conformance fixtures.
 */
final class PipelineHarness
{
    public const BASE = 'http://127.0.0.1:4010';

    public const JOB = 'job_1';

    public readonly FakeApi $api;

    public readonly FakePartTransport $parts;

    public readonly Pipeline $pipeline;

    public readonly Credentials $credentials;

    /** @var list<string> */
    public array $logLines = [];

    public readonly int $size;

    public readonly int $partSize;

    /**
     * @param  \Closure(array{method: string, url: string, authorization: string, length: int, headers: array<mixed>}, int): array{Response|PartFailure, float}|null  $script
     */
    public function __construct(
        public readonly FakeClock|FakeIdleClock $clock = new FakeClock(),
        public readonly string $file = 'cbr-20s.mp3',
        public readonly int $totalParts = 3,
        ?\Closure $script = null,
        int $maxRetries = 3,
        ?PartTransport $transport = null,
    ) {
        $this->size = (int) filesize($this->path());
        $this->partSize = (int) ceil($this->size / $totalParts);
        $this->api = new FakeApi();
        $this->parts = new FakePartTransport($clock, $script ?? static fn (array $request): array => [self::stored(), 0.0]);
        $this->credentials = new Credentials(Tokens::TEST);
        $ticker = new WaitTicker();
        $retry = new RetryMiddleware(
            $this->api,
            $this->credentials,
            self::BASE,
            $clock,
            new Log('debug', function (string $line): void {
                $this->logLines[] = $line;
            }),
            $maxRetries,
            jitter: static fn (float $ceiling): float => $ceiling,
            ticker: $ticker,
        );
        $api = new Exchange($retry, $this->credentials, self::BASE);
        $this->pipeline = new Pipeline(
            $api,
            $this->credentials,
            self::BASE,
            $clock,
            new Log('debug', function (string $line): void {
                $this->logLines[] = $line;
            }),
            $maxRetries,
            fn (): PartTransport => $transport ?? $this->parts,
            static fn (float $ceiling): float => $ceiling,
            $ticker,
        );
        $this->defaults();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function jobsWait(string $id, array $options): array
    {
        return $this->pipeline->wait($id, $options);
    }

    public function path(): string
    {
        return Conformance::dir().'/fixtures/media/'.$this->file;
    }

    public static function stored(string $etag = '"etag"'): Response
    {
        return new Response(200, ['ETag' => $etag]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createBody(?int $parts = null, bool $proxied = false, string $state = 'uploading'): array
    {
        $parts ??= $this->totalParts;
        $urls = [];
        for ($n = 1; $n <= min(16, $parts); $n++) {
            $urls[] = ['n' => $n, 'url' => self::url($n)];
        }

        return [
            'job_id' => self::JOB,
            'state' => $state,
            'upload' => $proxied ? null : [
                'part_size_bytes' => $this->partSize,
                'total_parts' => $parts,
                'part_urls' => $urls,
                'next_part_number' => $parts > 16 ? 17 : null,
            ],
        ];
    }

    public static function url(int $n, string $suffix = ''): string
    {
        return 'https://r2.example.com/hushscript/'.self::JOB.'/'.$n.'?X-Amz-Signature=SECRET'.$n.$suffix;
    }

    /** @param array<mixed> $body */
    public static function json(int $status, array $body = []): Response
    {
        return FakeHttp::json($status, $body);
    }

    /** The routes of a happy path; add routes over them, the latest wins. */
    private function defaults(): void
    {
        $this->api
            ->on('GET', '/v1/account', fn (): Response => self::json(200, ['balance_seconds' => 36000, 'held_seconds' => 0, 'limits' => ['max_daily_seconds' => 108000, 'used_today_seconds' => 0]]))
            ->on('POST', '/v1/uploads', fn (): Response => self::json(200, $this->createBody()))
            ->on('POST', '/v1/uploads/[^/]+/heartbeat', fn (): Response => self::json(200, ['ok' => true]))
            ->on('POST', '/v1/uploads/[^/]+/complete', fn (): Response => self::json(200, ['ok' => true, 'state' => 'queued']))
            ->on('PUT', '/v1/uploads/[^/]+/parts/\d+', fn (): Response => self::json(200, ['ok' => true]))
            ->on('GET', '/v1/jobs/[^/]+', fn (): Response => self::json(200, ['id' => self::JOB, 'state' => 'done', 'transcript_id' => 'tr_1']))
            ->on('GET', '/v1/transcripts/tr_1', fn (): Response => self::json(200, ['id' => 'tr_1', 'utterances' => [['text' => 'hello']]]));
    }
}
