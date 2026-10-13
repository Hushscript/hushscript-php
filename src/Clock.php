<?php

declare(strict_types=1);

namespace Hushscript;

/**
 * The time source for pacing and backoff; tests and the conformance runner inject their own.
 */
interface Clock
{
    /** Seconds on a monotonic scale. */
    public function now(): float;

    public function sleep(float $seconds): void;
}
