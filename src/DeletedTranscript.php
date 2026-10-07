<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** The record kept after a transcript was removed; its content is gone. */
final readonly class DeletedTranscript
{
    /**
     * @param  \DateTimeImmutable  $createdAt  when the transcript was uploaded
     * @param  string  $cause  `manual`, `scheduled`, `dormant` or `expired`; a cause the SDK does not know yet passes through
     * @param  array<mixed>|null  $receipt  the stored deletion receipt, opaque and as the API sends it (snake_case keys, string timestamps)
     */
    private function __construct(
        public string $id,
        public string $title,
        public int $durationSeconds,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $deletedAt,
        public string $cause,
        public ?array $receipt,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $row): self
    {
        return new self(
            $row->string('id'),
            $row->string('title'),
            $row->count('duration_seconds'),
            $row->timestamp('created_at'),
            $row->timestamp('deleted_at'),
            $row->string('cause'),
            $row->opaque('receipt'),
        );
    }
}
