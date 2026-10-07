<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils as Psr7;
use Hushscript\Clock;
use Hushscript\Credentials;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\ErrorMapper;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\UploadError;
use Hushscript\Http\Body;
use Hushscript\Http\Exchange;
use Hushscript\Http\Outcome;
use Hushscript\Http\RetryClass;
use Hushscript\Http\RetryPolicy;
use Hushscript\IdleClock;
use Hushscript\Log;
use Hushscript\Redact;
use Hushscript\Secret;
use Hushscript\Version;
use Psr\Http\Message\ResponseInterface;

/**
 * Uploads the parts of one job (spec 8.4): `concurrency` requests in flight, direct to R2 with a
 * fallback to the API.
 *
 * PHP has no background threads, so this is one loop that looks at its transfers, applies the retry
 * table to each answer, sends the heartbeat when it is due and, when only time is left to wait for,
 * lets the clock pass. Every part is a small state machine (PartRun); the way the upload goes is one
 * state (UploadState) that no answer can set twice. Memory stays at `concurrency x part_size`: the
 * bytes of a part are read when it starts and dropped when it is acknowledged.
 */
final class Uploader
{
    public const PART_TIMEOUT = 300.0;

    private const OCTET_STREAM = 'application/octet-stream';

    public readonly Plan $plan;

    public readonly UploadState $state;

    private readonly UrlBook $book;

    /** How long the latest part request held the loop, when its transport answered before `send` returned; 0 for one that is still in flight. */
    private float $blocked = 0.0;

    /**
     * @param  \Closure(float): float  $jitter  maps a ceiling to a delay in [0, ceiling]
     */
    public function __construct(
        private readonly PartTransport $transport,
        private readonly Exchange $api,
        private readonly Credentials $credentials,
        private readonly Clock $clock,
        private readonly Log $log,
        private readonly string $baseUrl,
        private readonly string $jobId,
        ?Plan $plan,
        private readonly Source $source,
        private readonly int $concurrency,
        private readonly int $maxRetries,
        private readonly Heartbeat $heartbeat,
        private readonly \Closure $jitter,
    ) {
        $this->plan = $plan === null ? Plan::proxied($source->size) : $plan->checkedFor($source->size);
        $this->state = new UploadState($plan === null ? UploadState::PROXIED : UploadState::UNDECIDED);
        $this->book = new UrlBook($clock);
        $this->book->store($this->plan->urls(), $this->plan->nextPart);
        $this->book->direct = $this->plan->directAvailable;
    }

    /**
     * @param  \Closure(int): void  $onBytes  called with the bytes acknowledged so far
     */
    public function upload(\Closure $onBytes): void
    {
        $total = $this->plan->totalParts;
        $next = 1;
        $sent = 0;
        /** @var array<int, PartRun> $running */
        $running = [];
        try {
            while (true) {
                while (count($running) < $this->concurrency && $next <= $total) {
                    $running[$next] = $this->begin($next);
                    $next++;
                }
                if ($running === []) {
                    break;
                }
                $this->pass($running);
                $this->look($running);
                $before = $sent;
                foreach ($running as $n => $run) {
                    if ($run->done) {
                        $sent += $run->length();
                        unset($running[$n]);
                    }
                }
                if ($sent !== $before) {
                    $onBytes($sent);
                }
                $this->heartbeat->raiseIfFailed();
            }
        } catch (\Throwable $failure) {
            foreach ($running as $run) {
                $run->promise?->cancel();
            }

            throw $failure;
        }
    }

    private function begin(int $n): PartRun
    {
        [$offset, $length] = $this->plan->range($this->source->size, $n);
        $run = new PartRun($n, $this->source->readRange($offset, $length));
        $this->start($run);

        return $run;
    }

    /**
     * One look at the transfers: let them progress, act on every answer, ping when due.
     *
     * @param  array<int, PartRun>  $running
     */
    private function look(#[\SensitiveParameter] array $running): void
    {
        $inFlight = 0;
        foreach ($running as $run) {
            if ($run->promise !== null) {
                $inFlight++;
            }
        }
        if ($inFlight > 0) {
            $this->transport->tick();
        }
        foreach ($running as $run) {
            if ($run->answer !== null) {
                $this->answered($run);
            } elseif ($run->promise === null && $run->notBefore !== null && $this->clock->now() >= $run->notBefore) {
                $this->start($run);
            }
        }
        $this->heartbeat->tick();
    }

    /**
     * Lets time pass when there is nothing to do but wait: the wall does it for a real clock while
     * transfers run; an injected clock is told how far it may go (`IdleClock`), and a clock that only
     * sleeps is slept to the next retry or heartbeat when nothing is in flight.
     *
     * @param  array<int, PartRun>  $running
     */
    private function pass(#[\SensitiveParameter] array $running): void
    {
        $inFlight = 0;
        $deadline = $this->heartbeat->deadline();
        foreach ($running as $run) {
            if ($run->done) {
                continue;
            }
            if ($run->promise !== null) {
                $inFlight++;
            } elseif ($run->notBefore !== null) {
                $deadline = min($deadline, $run->notBefore);
            }
        }
        if ($inFlight === 0 && ! is_finite($deadline)) {
            return;
        }
        if ($this->clock instanceof IdleClock) {
            $this->clock->idle($inFlight, $deadline);
        } elseif ($inFlight === 0 && is_finite($deadline) && $deadline > $this->clock->now()) {
            $this->clock->sleep($deadline - $this->clock->now());
        }
    }

    private function start(PartRun $run): void
    {
        $run->notBefore = null;
        $run->answer = null;
        // A transport that finishes a request inside `send` holds the loop for as long as the part
        // takes, so the ping goes out before a part that could outlast the interval, not after it.
        $this->heartbeat->tick($this->blocked);
        if (! $run->proxied && $this->state->proxied()) {
            $this->toProxied($run);
        }
        if (! $run->proxied && $run->url === null) {
            $this->assignUrl($run);
        }
        $request = $run->proxied ? $this->proxiedRequest($run) : $this->directRequest($run);
        $run->startedAt = $this->clock->now();
        $promise = $this->transport->send($request, self::PART_TIMEOUT);
        // The latest part, not the longest: one slow part must not make every later start ping.
        $this->blocked = $promise->getState() !== PromiseInterface::PENDING ? $this->clock->now() - $run->startedAt : 0.0;
        $run->promise = $promise;
        $promise->then(
            static function (#[\SensitiveParameter] ResponseInterface $response) use ($run): void {
                $run->answer = $response;
            },
            static function (#[\SensitiveParameter] \Throwable $failure) use ($run): void {
                $run->answer = $failure instanceof PartFailure ? $failure : PartFailure::of($failure);
            },
        );
    }

    private function toProxied(PartRun $run): void
    {
        $run->proxied = true;
        $run->url = null;
        $run->attempt = 0;
    }

    private function directRequest(PartRun $run): Request
    {
        // A presigned URL carries its own authorization: the token never goes to storage.
        try {
            $uri = new Uri($run->url?->reveal() ?? '');
        } catch (\InvalidArgumentException) {
            throw Shape::bad('an upload URL', $this->plan->status);
        }
        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            throw Shape::bad('an upload URL', $this->plan->status);
        }

        return new Request('PUT', $uri, ['User-Agent' => self::agent()], Psr7::streamFor($run->data));
    }

    private function proxiedRequest(PartRun $run): Request
    {
        return new Request(
            'PUT',
            $this->baseUrl.$this->proxiedPath($run->n),
            [
                'User-Agent' => self::agent(),
                // Read per attempt, after any wait: a rotation applies to a retry already queued (spec 7.4).
                'Authorization' => 'Bearer '.$this->credentials->token(),
                'Content-Type' => self::OCTET_STREAM,
            ],
            Psr7::streamFor($run->data),
        );
    }

    private function proxiedPath(int $n): string
    {
        return '/v1/uploads/'.Shape::segment($this->jobId, 'job id').'/parts/'.$n;
    }

    private static function agent(): string
    {
        return 'hushscript-php/'.Version::SDK.' php/'.PHP_VERSION;
    }

    private function answered(PartRun $run): void
    {
        $answer = $run->answer;
        $run->promise = null;
        $run->answer = null;
        $retryAfter = $answer instanceof ResponseInterface ? $answer->getHeaderLine('Retry-After') : '';
        $outcome = $answer instanceof ResponseInterface
            ? new Outcome($answer->getStatusCode(), ErrorMapper::parseRetryAfter($retryAfter === '' ? null : $retryAfter))
            : new Outcome(failed: true);
        $status = $outcome->status;
        $success = $status !== null && $status >= 200 && $status < 300;
        $decision = $success ? null : RetryPolicy::decide(RetryClass::Part, $run->attempt, $this->maxRetries, $outcome, $this->jitter);
        $this->log->attempt(
            'PUT',
            $run->proxied ? $this->proxiedPath($run->n) : 'r2:part/'.$run->n,
            $status,
            $run->attempt + 1,
            ($this->clock->now() - $run->startedAt) * 1000,
            $decision !== null && $decision->retry ? $decision->delay : null,
        );
        if ($success && $answer instanceof ResponseInterface) {
            $this->acknowledged($run, $answer);

            return;
        }
        if ($decision !== null && $decision->retry) {
            $run->attempt++;
            $run->notBefore = $this->clock->now() + $decision->delay;

            return;
        }
        if ($run->proxied) {
            throw $this->proxiedFailure($run, $answer);
        }
        $this->directGaveUp($run, $status, $answer);
    }

    private function acknowledged(PartRun $run, #[\SensitiveParameter] ResponseInterface $response): void
    {
        if ($run->proxied) {
            $run->done = true;

            return;
        }
        if ($this->state->proxied()) {
            // The upload went through the API while this request was out: the part is sent again that
            // way, whatever storage answered.
            $this->toProxied($run);
            $this->start($run);

            return;
        }
        $etag = trim($response->getHeaderLine('ETag'), '"');
        if ($etag === '') {
            throw new UploadError('Storage accepted part '.$run->n.' but sent no ETag.', 'etag_missing');
        }
        $this->state->record($run->n, $etag);
        $run->done = true;
    }

    private function directGaveUp(PartRun $run, ?int $status, #[\SensitiveParameter] ResponseInterface|PartFailure|null $answer): void
    {
        $action = $this->state->decide($run, $status);
        if ($action === UploadState::EXHAUSTED) {
            throw new UploadError('Part '.$run->n.' could not be uploaded after its retries and a fresh URL.', 'parts_exhausted', previous: $this->directFailure($run, $answer));
        }
        if ($action === UploadState::FALLBACK) {
            $this->toProxied($run);
        } else {
            try {
                $this->refresh($run->n, $run->url);
                $run->url = null;
                $run->attempt = 0;
            } catch (HushscriptException $failure) {
                $this->urlsFailed($run, $failure);
            }
        }
        $this->start($run);
    }

    /** What the last direct PUT of the part came to, as a cause that holds no URL or signature. */
    private function directFailure(PartRun $run, #[\SensitiveParameter] ResponseInterface|PartFailure|null $answer): \Throwable
    {
        $where = 'The direct upload of part '.$run->n;
        if ($answer instanceof ResponseInterface) {
            return new ApiError($where.' was answered with '.$answer->getStatusCode().'.', 'http_'.$answer->getStatusCode(), status: $answer->getStatusCode());
        }

        return new NetworkError($where.' failed: '.Redact::text($answer instanceof PartFailure ? $answer->getMessage() : 'no answer').'.');
    }

    /**
     * The part-urls fetch behind a part failed (spec 8.4, R8, as in the Go and TypeScript SDKs): a
     * terminal 4xx once a direct PUT has succeeded is the answer itself; otherwise the part goes
     * through the API while no direct PUT has succeeded, and is `parts_exhausted` after one has.
     */
    private function urlsFailed(PartRun $run, #[\SensitiveParameter] HushscriptException $failure): void
    {
        if ($failure instanceof UploadError && $failure->errorCode === 'part_url_missing') {
            throw $failure;
        }
        if ($this->state->direct() && self::terminal($failure)) {
            throw $failure;
        }
        if ($this->state->fallBack()) {
            $this->toProxied($run);

            return;
        }

        throw new UploadError('Part '.$run->n.' could not be uploaded after its retries and a fresh URL.', 'parts_exhausted', previous: $failure);
    }

    /** A 4xx other than 429 (which was already retried): the API said no, and asking again will not change it. */
    private static function terminal(#[\SensitiveParameter] HushscriptException $failure): bool
    {
        return $failure->status !== null && $failure->status >= 400 && $failure->status < 500 && $failure->status !== 429;
    }

    private function assignUrl(PartRun $run): void
    {
        try {
            $url = $this->urlFor($run->n);
        } catch (HushscriptException $failure) {
            $this->urlsFailed($run, $failure);

            return;
        }
        if ($url !== null) {
            $run->url = $url;

            return;
        }
        // The API has no direct URLs for this job: the parts go through it, unless storage has taken one already.
        if (! $this->state->fallBack()) {
            throw new UploadError('The API gave no upload URL for part '.$run->n.'.', 'part_url_missing');
        }
        $this->toProxied($run);
    }

    private function proxiedFailure(PartRun $run, #[\SensitiveParameter] ResponseInterface|PartFailure|null $answer): \Throwable
    {
        $path = $this->proxiedPath($run->n);
        if ($answer instanceof ResponseInterface) {
            return ErrorMapper::fromResponse($answer, $this->credentials, 'PUT', $path);
        }

        return new NetworkError(
            'Could not reach the Hushscript API: PUT '.$path.'.',
            previous: new \RuntimeException($this->credentials->scrub($answer instanceof PartFailure ? $answer->getMessage() : 'no answer')),
        );
    }

    /** The URL of part `$n`; null when the job turns out to take no direct URLs. */
    private function urlFor(int $n): ?Secret
    {
        $url = $this->book->get($n);
        if ($url === null && $this->book->direct) {
            $this->fetchFor($n);
            $url = $this->book->get($n);
        }
        if ($url === null && $this->book->direct) {
            throw new UploadError('The API gave no upload URL for part '.$n.'.', 'part_url_missing');
        }

        return $this->book->direct ? $url : null;
    }

    private function fetchFor(int $n): void
    {
        $start = $this->book->nextStart;
        if ($start === null || $n < $start) {
            $this->fetch($n, false);

            return;
        }
        while ($this->book->direct && $this->book->get($n) === null && $this->book->nextStart !== null) {
            $this->fetch($this->book->nextStart, true);
        }
    }

    private function refresh(int $n, ?Secret $failedUrl): void
    {
        // Another part may already have fetched this page since the URL failed.
        if ($failedUrl !== null && $this->book->current($n)?->equals($failedUrl) === true) {
            $this->fetch($n, false);
        }
    }

    /**
     * One `part-urls` page. The pages after the first come in order (`$advance`); a refresh of an
     * earlier page leaves the cursor of the pages still to come where it was.
     */
    private function fetch(int $start, bool $advance): void
    {
        $plan = $this->api->fetchOrNull('GET', '/v1/uploads/'.Shape::segment($this->jobId, 'job id').'/part-urls', ['start' => $start], static fn (#[\SensitiveParameter] Body $page, int $status): Plan => Plan::parse($page->raw(), $status));
        if ($plan === null || $plan->urls() === []) {
            $this->book->direct = false;

            return;
        }
        $cursor = $advance ? $plan->nextPart : $this->book->nextStart;
        if ($advance && $cursor !== null && $cursor <= $start) {
            $cursor = null;
        }
        $this->book->store($plan->urls(), $cursor);
    }
}
