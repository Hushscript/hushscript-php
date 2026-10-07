<?php

declare(strict_types=1);

namespace Hushscript\Http;

/** What one attempt produced: a response (status, Retry-After, slug) or a transport failure. */
final readonly class Outcome
{
    public function __construct(
        public ?int $status = null,
        public ?int $retryAfter = null,
        public ?string $slug = null,
        public bool $failed = false,
        public bool $notSent = false,
    ) {}
}
