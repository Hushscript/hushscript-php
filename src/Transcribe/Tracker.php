<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

/** What an error raised after the create was sent has to carry (spec 7.3). */
final class Tracker
{
    public function __construct(
        public readonly string $key,
        public ?string $token = null,
    ) {
    }
}
