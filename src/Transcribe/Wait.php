<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Api;
use Hushscript\Clock;
use Hushscript\Exception\JobFailedError;
use Hushscript\Exception\UploadError;
use Hushscript\Exception\WaitTimeoutError;

/**
 * Polls a job and fetches its transcript (spec 8.6).
 */
final class Wait
{
    public const FIRST_DELAY = 5.0;

    public const GROWTH = 1.5;

    public const MAX_DELAY = 15.0;

    private const TERMINAL = ['done', 'failed'];

    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function job(Api $api, string $jobId): array
    {
        $body = Shape::record($api->request('GET', '/v1/jobs/'.Shape::segment($jobId, 'job id')), 'a job');
        if (! is_string($body['state'] ?? null)) {
            throw Shape::bad('a job without a state');
        }

        return $body;
    }

    /**
     * Polls at 5 s, times 1.5 up to 15 s, until `done` or `failed`; a job still running at the
     * timeout raises WaitTimeoutError (it keeps running on the server). `$first` is a job read a
     * moment ago, which stands in for the first poll.
     *
     * @param  array<string, mixed>|null  $first
     * @param  (\Closure(array<string, mixed>): void)|null  $onProgress
     * @return array<string, mixed>
     */
    public static function until(Api $api, Clock $clock, string $jobId, float $waitTimeout, ?\Closure $onProgress, ?string $token, ?array $first = null): array
    {
        $started = $clock->now();
        $delay = self::FIRST_DELAY;
        while (true) {
            $job = $first ?? self::job($api, $jobId);
            $first = null;
            $state = is_string($job['state']) ? $job['state'] : '';
            if (in_array($state, self::TERMINAL, true)) {
                return $job;
            }
            if ($onProgress !== null && in_array($state, ['queued', 'processing'], true)) {
                $onProgress(['stage' => 'processing', 'state' => $state]);
            }
            $remaining = $started + $waitTimeout - $clock->now();
            if ($remaining <= 0) {
                $timeout = new WaitTimeoutError(
                    'The job was still running after '.rtrim(rtrim(sprintf('%.3f', $waitTimeout), '0'), '.').' s. It keeps running on the server.',
                );
                $timeout->resumeToken = $token;

                throw $timeout;
            }
            $clock->sleep(min($delay, $remaining));
            $delay = min($delay * self::GROWTH, self::MAX_DELAY);
        }
    }

    /**
     * The transcript id of a finished job.
     *
     * @param  array<string, mixed>  $job
     */
    public static function transcriptId(array $job): string
    {
        if (($job['state'] ?? null) === 'failed') {
            $error = $job['error'] ?? null;
            $kind = $job['failure_kind'] ?? null;
            $id = $job['id'] ?? null;

            throw new JobFailedError(
                'The transcription failed.',
                is_string($id) ? $id : null,
                is_string($error) ? $error : null,
                is_string($kind) ? $kind : null,
            );
        }
        $transcript = $job['transcript_id'] ?? null;
        if (! is_string($transcript) || $transcript === '') {
            $id = is_string($job['id'] ?? null) ? $job['id'] : 'The job';

            throw new UploadError($id.' is done but names no transcript.', 'transcript_missing');
        }

        return $transcript;
    }

    /** @return array<string, mixed> */
    public static function transcript(Api $api, string $transcriptId): array
    {
        return Shape::record($api->request('GET', '/v1/transcripts/'.Shape::segment($transcriptId, 'transcript id')), 'a transcript');
    }
}
