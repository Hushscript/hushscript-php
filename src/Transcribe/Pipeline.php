<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Api;
use Hushscript\Clock;
use Hushscript\Credentials;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\NotFoundError;
use Hushscript\Log;

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
        private readonly Api $api,
        private readonly Credentials $credentials,
        private readonly string $baseUrl,
        private readonly Clock $clock,
        private readonly Log $log,
        private readonly int $maxRetries,
        private readonly \Closure $parts,
        private readonly \Closure $jitter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $rawOptions
     * @return array<string, mixed>  the transcript as the API sent it
     */
    public function transcribe(string $path, #[\SensitiveParameter] array $rawOptions): array
    {
        $options = Options::forTranscribe($rawOptions);
        $source = Source::open($path);
        [$size, $duration] = Preflight::media($source, $options);
        Preflight::account($this->api->request('GET', '/v1/account'), $duration, $options);
        $key = $options->newKey();
        $state = Resume::state('', $key, $size, $duration);
        $body = Preflight::createBody($size, $duration, $options, $key);

        return $this->annotated($key, null, function (Tracker $tracker) use ($options, $source, $state, $body): array {
            $options->progress(self::uploading(0, 0, $state->size));
            $created = $this->create($body);
            // The create is out: whatever happens from here, the job may exist.

            return $this->afterCreate($created, $tracker, $state, $source, $options);
        });
    }

    /**
     * @param  array<string, mixed>  $rawOptions
     * @return array<string, mixed>
     */
    public function resume(#[\SensitiveParameter] string $token, ?string $path, #[\SensitiveParameter] array $rawOptions): array
    {
        $options = Options::forResume($rawOptions);
        $state = Resume::decode($token);

        return $this->annotated($state->idempotencyKey, $token, function (Tracker $tracker) use ($options, $state, $path, $token): array {
            // A path is only sized now (a different size is refused before any request) and opened later,
            // once the job is known to be uploading.
            $early = $path === null ? null : Source::sizeOf($path);
            if ($early !== null) {
                $state->checkSameFile($early);
            }
            try {
                $job = Wait::job($this->api, $state->jobId);
            } catch (NotFoundError $missing) {
                throw Resume::jobMissing($missing);
            }
            if ($job['state'] !== self::UPLOADING) {
                return $this->finish($state->jobId, $options, $token, $job);
            }
            if ($path === null) {
                throw Resume::fileRequired();
            }
            $source = Source::open($path);
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
     * Polls a job until it is done or failed; the finished job, as the API sent it.
     *
     * @param  array<string, mixed>  $rawOptions
     * @return array<string, mixed>
     */
    public function wait(string $jobId, array $rawOptions): array
    {
        $options = Options::forWait($rawOptions);
        Shape::segment($jobId, 'job id');

        return Wait::until($this->api, $this->clock, $jobId, $options->waitTimeout, $options->onProgress, null);
    }

    /**
     * Errors raised once the create was sent carry the idempotency key and, once the job is known,
     * its resume token (spec 7.3).
     *
     * @param  \Closure(Tracker): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function annotated(string $key, ?string $token, \Closure $run): array
    {
        $tracker = new Tracker($key, $token);
        try {
            return $run($tracker);
        } catch (HushscriptException $failure) {
            $failure->idempotencyKey ??= $tracker->key;
            $failure->resumeToken ??= $tracker->token;

            throw $failure;
        }
    }

    /** @param array<string, mixed> $body */
    private function create(#[\SensitiveParameter] array $body): Created
    {
        // The transport paces the create to the 4 per minute the API allows (spec 7.5).
        return Created::parse(Shape::record($this->api->request('POST', '/v1/uploads', body: $body), 'a create response'));
    }

    /**
     * The upload, wait and fetch that follow a create, for a new call and a resume alike.
     *
     * @return array<string, mixed>
     */
    private function afterCreate(Created $created, Tracker $tracker, Resume $state, ?Source $source, Options $options): array
    {
        $token = $state->withJob($created->jobId)->encode();
        $tracker->token = $token;
        if ($options->onResumeToken !== null) {
            ($options->onResumeToken)($token);
        }
        if ($created->state === self::UPLOADING) {
            if ($source === null) {
                throw Resume::fileRequired();
            }
            $this->upload($created, $source, $options);
        }

        return $this->finish($created->jobId, $options, $token, null);
    }

    private function upload(Created $created, Source $source, Options $options): void
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
            $options->concurrency,
            $this->maxRetries,
            $heartbeat,
            $this->jitter,
        );
        $total = $source->size;
        $uploader->upload(static function (int $sent) use ($options, $total): void {
            $options->progress(self::uploading(intdiv(min($sent, $total) * 100, max(1, $total)), $sent, $total));
        });
        $heartbeat->raiseIfFailed();
        // One more ping right before complete, so a long last part cannot leave the job idle.
        $heartbeat->beat();
        $heartbeat->raiseIfFailed();
        $body = $uploader->state->partsBody();
        $this->api->request('POST', $jobPath.'/complete', body: $body);
    }

    /**
     * @param  array<string, mixed>|null  $first  a job read a moment ago
     * @return array<string, mixed>
     */
    private function finish(string $jobId, Options $options, string $token, ?array $first): array
    {
        $job = Wait::until($this->api, $this->clock, $jobId, $options->waitTimeout, $options->onProgress, $token, $first);

        return $this->toTranscript(Wait::transcript($this->api, Wait::transcriptId($job)));
    }

    /**
     * The seam where the transcript body becomes the caller's object; until the Transcript type
     * exists the call returns the body as the API sent it.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function toTranscript(array $body): array
    {
        return $body;
    }

    /** @return array<string, mixed> */
    private static function uploading(int $percent, int $sent, int $total): array
    {
        return ['stage' => 'uploading', 'percent' => $percent, 'bytes_sent' => $sent, 'bytes_total' => $total];
    }
}
