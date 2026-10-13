<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** One row of the transcript list. */
final readonly class TranscriptSummary
{
    use RawBody;

    /** @param list<string> $tags */
    private function __construct(
        public string $id,
        public string $title,
        public ?string $language,
        public int $durationSeconds,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $autoDeleteAt,
        public array $tags,
        /** @var array<mixed> */
        #[\SensitiveParameter] private array $raw,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $row): self
    {
        return new self(
            $row->string('id'),
            $row->string('title'),
            $row->nullableString('language_detected'),
            $row->count('duration_seconds'),
            $row->timestamp('created_at'),
            $row->nullableTimestamp('auto_delete_at'),
            $row->strings('tags'),
            $row->raw(),
        );
    }
}
