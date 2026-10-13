<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use Hushscript\Clock;

final class FakeClock implements Clock
{
    public float $time = 0.0;

    /** @var list<float> */
    public array $sleeps = [];

    /** @var (\Closure(float): void)|null */
    public ?\Closure $onSleep = null;

    private int $reads = 0;

    /** @return list<float> */
    public function slept(): array
    {
        return $this->sleeps;
    }

    #[\Override]
    public function now(): float
    {
        LoopGuard::check(++$this->reads);

        return $this->time;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += $seconds;
        if ($this->onSleep !== null) {
            ($this->onSleep)($seconds);
        }
    }
}
