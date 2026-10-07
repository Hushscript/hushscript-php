<?php

declare(strict_types=1);

namespace Hushscript\Http;

use Hushscript\Clock;

/**
 * Lets work that must happen on a schedule (the upload's heartbeat) go on while a request sleeps
 * between attempts. PHP has no background thread, so a Retry-After of a minute would otherwise stop
 * the heartbeat for a minute: while a ticker is attached, a sleep is cut at the moment the attached
 * work is due and the work is run before the sleep goes on.
 */
final class WaitTicker
{
    /** @var (\Closure(): float)|null */
    private ?\Closure $deadline = null;

    /** @var (\Closure(): void)|null */
    private ?\Closure $tick = null;

    private bool $ticking = false;

    /**
     * @param  \Closure(): float  $deadline  clock time the work is next due; INF when it never is
     * @param  \Closure(): void  $tick  does the work if it is due
     */
    public function attach(\Closure $deadline, \Closure $tick): void
    {
        $this->deadline = $deadline;
        $this->tick = $tick;
    }

    public function detach(): void
    {
        $this->deadline = null;
        $this->tick = null;
    }

    public function sleep(Clock $clock, float $seconds): void
    {
        $deadline = $this->deadline;
        $tick = $this->tick;
        // A sleep inside the attached work (a ping that is itself retried after a Retry-After) is a plain
        // sleep: the work does not start itself again from within.
        if ($deadline === null || $tick === null || $this->ticking) {
            $clock->sleep($seconds);

            return;
        }
        $end = $clock->now() + $seconds;
        while (true) {
            $this->ticking = true;
            try {
                $tick();
            } finally {
                $this->ticking = false;
            }
            $left = $end - $clock->now();
            if ($left <= 0) {
                return;
            }
            $due = $deadline() - $clock->now();
            $clock->sleep($due > 0 && $due < $left ? $due : $left);
        }
    }
}
