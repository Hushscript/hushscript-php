<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** Seconds reserved by work still running; they are already deducted from the balance. */
final readonly class Hold
{
    use RawBody;

    /** @param string $kind `transcription`, `translation`, `insights` or `qa`; a kind the SDK does not know yet passes through */
    private function __construct(
        public string $id,
        public string $kind,
        public int $seconds,
        public \DateTimeImmutable $createdAt,
        /** @var array<mixed> */
        #[\SensitiveParameter] private array $raw,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $hold): self
    {
        return new self(
            $hold->string('id'),
            $hold->string('kind'),
            $hold->count('seconds'),
            $hold->timestamp('created_at'),
            $hold->raw(),
        );
    }
}
