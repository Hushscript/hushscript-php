<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

/** A stream wrapper that refuses every open, standing in for one that cannot be built. */
final class RefusingWrapper
{
    /** @var resource|null set by PHP from the context given to fopen */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}
