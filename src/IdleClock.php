<?php

declare(strict_types=1);

namespace Hushscript;

/**
 * A clock whose time is moved by whoever owns it (the conformance runner and the unit tests), not by
 * the wall. While the upload waits on transfers that are already in flight, the SDK calls `idle()`
 * after each look at them; the clock may then let time pass, but not beyond `$deadline`, the next
 * moment the SDK itself has something to do (a heartbeat or a retry).
 */
interface IdleClock extends Clock
{
    /**
     * @param  int  $inFlight  part requests the SDK is waiting on
     * @param  float  $deadline  clock time of the SDK's next own event; INF when there is none
     */
    public function idle(int $inFlight, float $deadline): void;
}
