<?php

declare(strict_types=1);

namespace Hushscript\Format;

/** One subtitle cue; `$speakerName` is empty when the cue carries no speaker prefix. */
final class Cue
{
    public function __construct(
        public readonly int $startMs,
        public readonly int $endMs,
        public readonly string $speakerName,
        public readonly string $text,
    ) {
    }

    public function withSpeaker(): string
    {
        return $this->speakerName === '' ? $this->text : $this->speakerName.': '.$this->text;
    }
}
