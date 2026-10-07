<?php

declare(strict_types=1);

namespace Hushscript\Format;

use Hushscript\Utterance;

final class Srt
{
    // An SRT reader takes a line holding "-->" for a timing line, so a run of dashes before ">" is cut
    // to "->" (a plain "-->" swap would turn "--->" back into "-->").
    private const DASH_ARROW = '/-{2,}>/';

    private function __construct()
    {
    }

    /** @param list<Utterance> $utterances */
    public static function render(array $utterances, bool $captions = true): string
    {
        $blocks = [];
        foreach (self::cues($utterances, $captions) as $index => $cue) {
            $blocks[] = ($index + 1)."\n".Time::stamp($cue->startMs, ',').' --> '.Time::stamp($cue->endMs, ',')."\n"
                .(preg_replace(self::DASH_ARROW, '->', self::cueText($cue->withSpeaker())) ?? '');
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  list<Utterance>  $utterances
     * @return list<Cue>
     */
    public static function cues(array $utterances, bool $captions): array
    {
        return $captions ? Captions::segment($utterances) : Captions::utteranceCues($utterances);
    }

    /** Keeps a cue's line breaks but drops its blank lines: SRT and VTT end a cue at the first one. */
    public static function cueText(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

        return implode("\n", array_filter($lines, static fn (string $line): bool => Time::strip($line) !== ''));
    }
}
