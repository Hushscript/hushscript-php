<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** One movement of the credit balance. */
final readonly class LedgerEntry
{
    private function __construct(
        public int $id,
        public int $deltaSeconds,
        public string $kind,
        public ?string $jobId,
        public ?string $note,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $entry): self
    {
        return new self(
            $entry->integer('id'),
            $entry->integer('delta_seconds'),
            $entry->string('kind'),
            $entry->nullableString('job_id'),
            $entry->nullableString('note'),
            $entry->timestamp('created_at'),
        );
    }
}
