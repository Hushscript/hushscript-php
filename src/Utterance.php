<?php

declare(strict_types=1);

namespace Hushscript;

final readonly class Utterance
{
    /**
     * @param  string  $speaker  the provider's raw label; empty for a transcript without speaker labels
     * @param  string  $speakerName  the saved name, else `Speaker {label}`, else empty for an empty label
     */
    public function __construct(
        public string $speaker,
        public string $speakerName,
        public int $startMs,
        public int $endMs,
        public string $text,
    ) {
    }
}
