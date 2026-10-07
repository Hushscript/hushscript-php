<?php

declare(strict_types=1);

namespace Hushscript\Http;

use Hushscript\Clock;

/**
 * Paces `POST /v1/uploads` to 4 per 60 s per client (spec 7.5). reserve() books the next slot and
 * says how long the caller must wait before using it.
 */
final class Pacing
{
    public const CREATES_PER_WINDOW = 4;

    public const WINDOW_SECONDS = 60.0;

    /** @var list<float> */
    private array $slots = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly int $capacity = self::CREATES_PER_WINDOW,
        private readonly float $window = self::WINDOW_SECONDS,
    ) {}

    public function reserve(): float
    {
        $now = $this->clock->now();
        $this->slots = array_values(array_filter($this->slots, fn (float $slot): bool => $slot > $now - $this->window));
        if (count($this->slots) < $this->capacity) {
            $this->slots[] = $now;

            return 0.0;
        }
        $wait = $this->slots[0] + $this->window - $now;
        array_shift($this->slots);
        $this->slots[] = $now + $wait;

        return $wait;
    }
}
