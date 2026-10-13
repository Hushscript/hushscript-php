<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

/**
 * The answer to `POST /v1/uploads` (spec 8.3): the job, its state and the direct-to-R2 plan, which
 * is null in proxied mode and for a job that has already left `uploading`.
 */
final class Created
{
    private function __construct(
        public readonly string $jobId,
        public readonly string $state,
        public readonly ?Plan $plan,
    ) {
    }

    /**
     * @param  array<mixed>  $body
     *
     * @throws \Hushscript\Exception\ApiError bad_response
     */
    public static function parse(#[\SensitiveParameter] array $body, int $status): self
    {
        $jobId = $body['job_id'] ?? null;
        if (! is_string($jobId) || ! Shape::usable($jobId)) {
            throw Shape::bad('a create response without a job id', $status);
        }
        $upload = $body['upload'] ?? null;
        if ($upload !== null && (! is_array($upload) || array_is_list($upload))) {
            throw Shape::bad('an upload plan', $status);
        }
        $state = $body['state'] ?? null;

        return new self($jobId, is_string($state) ? $state : 'uploading', $upload === null ? null : Plan::parse($upload, $status));
    }
}
