<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Clock;
use Hushscript\Credentials;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\NotFoundError;
use Hushscript\Exception\PreflightError;
use Hushscript\Http\Body;
use Hushscript\Http\Exchange;
use Hushscript\Http\WaitTicker;
use Hushscript\Job;
use Hushscript\Log;
use Hushscript\Secret;
use Hushscript\Transcript;

/**
 * The transcribe pipeline (spec 8): preflight, create, parts, heartbeat, complete, wait and fetch,
 * and the resume of a call that ended before the transcript.
 */
final class Pipeline
{
    private const UPLOADING = 'uploading';

    /**
     * @param  \Closure(): PartTransport  $parts  the transport part requests leave through
     * @param  \Closure(float): float  $jitter  maps a ceiling to a delay in [0, ceiling]
     */
    public function __construct(
        private readonly Exchange $api,
        private readonly Credentials $credentials,
        private readonly string $baseUrl,
        private readonly Clock $clock,
        private readonly Log $log,
        private readonly int $maxRetries,
        private readonly \Closure $parts,
        private readonly \Closure $jitter,
        private readonly ?WaitTicker $ticker = null,
    ) {
    }

    /**
     * @param  mixed  $file  a path, or a stream resource the caller owns
     * @param  array<string, mixed>  $rawOptions
     */
    public function transcribe(mixed $file, #[\SensitiveParameter] array $rawOptions): Transcript
    {
        $options = Options::forTranscribe($rawOptions);
        if (is_string($file)) {
            $source = Source::open($file);
        } elseif (Source::isStream($file)) {
            $source = Source::stream($file, $options->size);
            if ($source->sequential && $options->durationSeconds === null) {
                throw new PreflightError(Source::NEEDS_SIZE, 'invalid_option');
            }
        } else {
            throw self::notAFile();
        }
        [$size, $duration] = Preflight::media($source, $options);
        Preflight::account($this->api->request('GET', '/v1/account'), $duration);
        $key = $options->newKey();
        $state = Resume::state('', $key, $size, $duration);
        $body = Preflight::createBody($size, $duration, $options, $key);

        return $this->annotated($key, null, $options, function (Tracker $tracker) use ($options, $source, $state, $body): Transcript {
            $options->progress(self::uploading(0, 0, $state->size));
            $created = $this->create($body);
            // The create is out: whatever happens from here, the job may exist.

            return $this->afterCreate($created, $tracker, $state, $source, $options);
        });
    }

    /**
     * @param  mixed  $file  a path, a stream resource the caller owns, or null
     * @param  array<string, mixed>  $rawOptions
     */
    public function resume(#[\SensitiveParameter] string $token, mixed $file, #[\SensitiveParameter] array $rawOptions): Transcript
    {
        $options = Options::forResume($rawOptions);
        if ($file !== null && ! is_string($file) && ! Source::isStream($file)) {
            throw self::notAFile();
        }
        $state = Resume::decode($token);
        $held = new Secret($token);

        return $this->annotated($state->idempotencyKey, $held, $options, function (Tracker $tracker) use ($options, $state, $file, $held): Transcript {
            // A path is only sized now (a different size is refused before any request) and opened later,
            // once the job is known to be uploading. A stream is measured now when it can seek; one that
            // cannot has the size of the token, and is read from its current position once the upload needs it.
            $stream = Source::isStream($file) ? Source::stream($file, null, $state->size) : null;
            $early = $stream !== null ? $stream->size : (is_string($file) ? Source::sizeOf($file) : null);
            if ($early !== null) {
                $state->checkSameFile($early);
            }
            try {
                $job = Wait::job($this->api, $state->jobId);
            } catch (NotFoundError $missing) {
                throw Resume::jobMissing($missing);
            }
            if ($job['state'] !== self::UPLOADING) {
                return $this->finish($state->jobId, $options, $held, $job);
            }
            if ($file === null) {
                throw Resume::fileRequired();
            }
            $source = $stream ?? Source::open($file);
            $state->checkSameFile($source->size);
            $options->progress(self::uploading(0, 0, $state->size));
            $created = $this->create([
                'size_bytes' => $state->size,
                'duration_seconds' => $state->durationSeconds,
                'idempotency_key' => $state->idempotencyKey,
            ]);
            if ($created->jobId !== $state->jobId) {
                throw Resume::jobMissing();
            }

            return $this->afterCreate($created, $tracker, $state, $source, $options);
        });
    }

    /**
     * Polls a job until it is done or failed; the finished job.
     *
     * @param  array<string, mixed>  $rawOptions
     */
    public function wait(string $jobId, array $rawOptions): Job
    {
        $options = Options::forWait($rawOptions);
        Shape::segment($jobId, 'job id');

        return $this->annotated(null, null, $options, fn (Tracker $tracker): Job => Job::fromApi(Body::of(
            Wait::until($this->api, $this->clock, $jobId, $options->waitTimeout, $options->progressSink(), null, full: true),
            'job',
        )));
    }

    /**
     * Errors raised once the create was sent carry the idempotency key and, once the job is known,
     * its resume token (spec 7.3).
     *
     * Once a request was sent, every failure reaches the caller as an SDK error (spec 9.2): one of the
     * SDK's own is annotated, anything else becomes `unexpected_error` with a redacted cause. What the
     * caller's own callbacks threw propagates unchanged, the same object, with nothing added.
     *
     * @template T
     *
     * @param  \Closure(Tracker): T  $run
     * @return T
     */
    private function annotated(#[\SensitiveParameter] ?string $key, ?Secret $token, Options $options, \Closure $run): mixed
    {
        $tracker = new Tracker($key ?? '', $token);
        try {
            return $run($tracker);
        } catch (\Throwable $failure) {
            if ($options->callbacks->raised($failure)) {
                throw $failure;
            }
            $error = $failure instanceof HushscriptException ? $failure : Exchange::wrapUnexpected($failure, $this->credentials);
            if ($key !== null) {
                $error->idempotencyKey ??= $tracker->key;
            }
            $error->resumeToken ??= $tracker->token?->reveal();

            throw $error;
        }
    }

    /** @param array<string, mixed> $body */
    private function create(#[\SensitiveParameter] array $body): Created
    {
        // The transport paces the create to the 4 per minute the API allows (spec 7.5).
        return $this->api->fetch('POST', '/v1/uploads', [], $body, static fn (#[\SensitiveParameter] Body $answer, int $status): Created => Created::parse($answer->raw(), $status));
    }

    /**
     * The upload, wait and fetch that follow a create, for a new call and a resume alike.
     */
    private function afterCreate(#[\SensitiveParameter] Created $created, Tracker $tracker, Resume $state, ?Source $source, Options $options): Transcript
    {
        $token = new Secret($state->withJob($created->jobId)->encode());
        $tracker->token = $token;
        $options->resumeToken($token->reveal());
        if ($created->state === self::UPLOADING) {
            if ($source === null) {
                throw Resume::fileRequired();
            }
            $this->upload($created, $source, $options);
        }

        return $this->finish($created->jobId, $options, $token, null);
    }

    private function upload(#[\SensitiveParameter] Created $created, Source $source, Options $options): void
    {
        $jobPath = '/v1/uploads/'.Shape::segment($created->jobId, 'job id');
        $heartbeat = new Heartbeat($this->clock, function () use ($jobPath): void {
            $this->api->request('POST', $jobPath.'/heartbeat');
        }, $this->log);
        $uploader = new Uploader(
            ($this->parts)(),
            $this->api,
            $this->credentials,
            $this->clock,
            $this->log,
            $this->baseUrl,
            $created->jobId,
            $created->plan,
            $source,
            $source->sequential ? 1 : $options->concurrency,
            $this->maxRetries,
            $heartbeat,
            $this->jitter,
        );
        $total = $source->size;
        // A request that sleeps between attempts (a Retry-After) must not stop the ping: it is sent from inside the sleep.
        $this->ticker?->attach($heartbeat->deadline(...), $heartbeat->tick(...));
        try {
            $uploader->upload(static function (int $sent) use ($options, $total): void {
                $options->progress(self::uploading(intdiv(min($sent, $total) * 100, max(1, $total)), $sent, $total));
            });
            $heartbeat->raiseIfFailed();
            if (! $source->atEnd()) {
                throw Source::sizeMismatch();
            }
            // One more ping right before complete, so a long last part cannot leave the job idle.
            $heartbeat->beat();
            $heartbeat->raiseIfFailed();
            $body = $uploader->state->partsBody();
            $this->api->request('POST', $jobPath.'/complete', body: $body);
        } finally {
            $this->ticker?->detach();
        }
    }

    /**
     * @param  array<string, mixed>|null  $first  a job read a moment ago
     */
    private function finish(string $jobId, Options $options, Secret $token, #[\SensitiveParameter] ?array $first): Transcript
    {
        $job = Wait::until($this->api, $this->clock, $jobId, $options->waitTimeout, $options->progressSink(), $token, $first);

        return Wait::transcript($this->api, Wait::transcriptId($job));
    }

    private static function notAFile(): PreflightError
    {
        return new PreflightError('file must be a path or an open stream resource.', 'invalid_option');
    }

    /** @return array<string, mixed> */
    private static function uploading(int $percent, int $sent, int $total): array
    {
        return ['stage' => 'uploading', 'percent' => $percent, 'bytes_sent' => $sent, 'bytes_total' => $total];
    }
}
