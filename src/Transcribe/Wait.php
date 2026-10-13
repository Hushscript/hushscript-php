<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Clock;
use Hushscript\Exception\JobFailedError;
use Hushscript\Exception\UploadError;
use Hushscript\Exception\WaitTimeoutError;
use Hushscript\Http\BadBody;
use Hushscript\Http\Body;
use Hushscript\Http\Exchange;
use Hushscript\Job;
use Hushscript\Secret;
use Hushscript\Transcript;

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

    /**
     * One read of a job. The polling reads only the fields it acts on, so a body that lacks a field the
     * public `Job` has is still a usable poll; `$full` also holds it to the whole `Job` shape.
     *
     * @return array<string, mixed>
     */
    public static function job(Exchange $api, string $jobId, bool $full = false): array
    {
        // The path is its own statement: coverage loses a line whose call ends in a closure, and a mutant on
        // a line without coverage runs no test.
        $path = '/v1/jobs/'.Shape::segment($jobId, 'job id');

        return $api->fetch('GET', $path, [], null, static function (#[\SensitiveParameter] Body $body) use ($full): array {
            $body->string('state');
            $body->nullableString('error');
            $body->nullableString('failure_kind');
            if ($body->has('id')) {
                $body->string('id');
            }
            // An id the server sends for the transcript goes into a URL path: one that cannot is an unusable body.
            $transcript = $body->nullableString('transcript_id');
            if ($transcript !== null && ! Shape::usable($transcript)) {
                throw new BadBody('response.transcript_id: expected an id');
            }
            if ($full) {
                Job::fromApi($body);
            }

            /** @var array<string, mixed> */
            return $body->raw();
        });
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
    public static function until(Exchange $api, Clock $clock, string $jobId, float $waitTimeout, ?\Closure $onProgress, ?Secret $token, #[\SensitiveParameter] ?array $first = null, bool $full = false): array
    {
        $started = $clock->now();
        $delay = self::FIRST_DELAY;
        while (true) {
            $job = $first ?? self::job($api, $jobId, $full);
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
                $timeout->resumeToken = $token?->reveal();

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
    public static function transcriptId(#[\SensitiveParameter] array $job): string
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

    public static function transcript(Exchange $api, string $transcriptId): Transcript
    {
        $path = '/v1/transcripts/'.Shape::segment($transcriptId, 'transcript id');

        return $api->fetch('GET', $path, [], null, static fn (#[\SensitiveParameter] Body $body): Transcript => Transcript::fromApi($body));
    }
}
