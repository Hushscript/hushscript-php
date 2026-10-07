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
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
