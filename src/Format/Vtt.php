<?php

declare(strict_types=1);

namespace Hushscript\Format;

use Hushscript\Utterance;

final class Vtt
{
    private function __construct()
    {
    }

    /** @param list<Utterance> $utterances */
    public static function render(array $utterances, bool $captions = true): string
    {
        $blocks = [];
        foreach (Srt::cues($utterances, $captions) as $index => $cue) {
            $blocks[] = ($index + 1)."\n".Time::stamp($cue->startMs, '.').' --> '.Time::stamp($cue->endMs, '.')."\n"
                .self::escape(Srt::cueText($cue->withSpeaker()));
        }

        return "WEBVTT\n\n".implode("\n\n", $blocks)."\n";
    }

    /** Escapes what WebVTT reads as markup (`&` and `<`) and as the end of a cue (`-->`). */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '-->'], ['&amp;', '&lt;', '--&gt;'], $text);
    }
}
