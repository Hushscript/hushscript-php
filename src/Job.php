<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/**
 * A processing job (spec 6.4). The private-result and from-URL fetch fields are left out; `$hs->api()`
 * still returns them.
 */
final readonly class Job
{
    /**
     * @param  string  $state  `uploading`, `fetching`, `queued`, `processing`, `done` or `failed`; a state the SDK does not know yet passes through
     * @param  ?string  $error  the failure text of a failed job; opaque
     * @param  ?string  $failureKind  `media_unreadable` or `media_undecodable` when the file itself could not be read
     */
    private function __construct(
        public string $id,
        public string $state,
        public ?string $transcriptId,
        public ?string $error,
        public ?string $failureKind,
        public string $mode,
        public ?int $declaredSeconds,
        public ?int $verifiedSeconds,
        public ?int $actualSeconds,
        public ?int $billableSeconds,
        public ?string $retryOf,
        public \DateTimeImmutable $createdAt,
        public JobOptions $options,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $job): self
    {
        return new self(
            $job->string('id'),
            $job->string('state'),
            $job->nullableString('transcript_id'),
            $job->nullableString('error'),
            $job->nullableString('failure_kind'),
            $job->string('mode'),
            $job->nullableCount('declared_seconds'),
            $job->nullableCount('verified_seconds'),
            $job->nullableCount('actual_seconds'),
            $job->nullableCount('billable_seconds'),
            $job->nullableString('retry_of'),
            $job->timestamp('created_at'),
            JobOptions::fromApi($job->child('options')),
        );
    }
}
