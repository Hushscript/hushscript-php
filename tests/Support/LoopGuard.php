<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

/**
 * Turns a loop that stopped making progress into a failed test: the fakes it reads on every turn
 * count their calls, and a count no legitimate test reaches throws instead of spinning until the
 * process times out or runs out of memory.
 */
final class LoopGuard
{
    public const LIMIT = 10_000;

    public static function check(int $calls): void
    {
        if ($calls > self::LIMIT) {
            throw new \LogicException('The loop is not progressing: '.$calls.' calls to a fake.');
        }
    }
}
