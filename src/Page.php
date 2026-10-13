<?php

declare(strict_types=1);

namespace Hushscript;

/**
 * One page of a list. `$nextCursor` is opaque and null on the last page.
 *
 * @template T
 */
final readonly class Page
{
    use RawBody;

    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        /** @var array<mixed> the answer as received, for `toJson()` */
        #[\SensitiveParameter] private array $raw = [],
    ) {
    }
}
