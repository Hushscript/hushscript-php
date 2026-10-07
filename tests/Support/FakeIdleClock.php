<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use Hushscript\IdleClock;

/**
 * A clock that moves only when the upload loop is waiting: to the sooner of its own deadline and the
 * next moment something in flight is due, as the conformance runner's clock does.
 */
final class FakeIdleClock implements IdleClock
{
    public float $time = 0.0;

    /** @var list<float> */
    public array $sleeps = [];

    /** @var (\Closure(): ?float)|null */
    public ?\Closure $nextEvent = null;

    /** @var list<float> clock time of every idle call */
    public array $idleAt = [];

    #[\Override]
    public function now(): float
    {
        return $this->time;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += $seconds;
    }

    #[\Override]
    public function idle(int $inFlight, float $deadline): void
    {
        $this->idleAt[] = $this->time;
        $next = $this->nextEvent === null ? null : ($this->nextEvent)();
        $target = min($deadline, $next ?? INF);
        if (! is_finite($target)) {
            throw new \LogicException('The upload is waiting for nothing: no deadline and no response due.');
        }
        $this->time = max($this->time, $target);
    }
}
