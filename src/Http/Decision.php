<?php

declare(strict_types=1);

namespace Hushscript\Http;

final readonly class Decision
{
    public function __construct(
        public bool $retry,
        public float $delay = 0.0,
    ) {
    }
}
