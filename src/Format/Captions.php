<?php

declare(strict_types=1);

namespace Hushscript\Format;

use Hushscript\Utterance;

/**
 * Re-cuts utterances into readable cues (spec 6.2): lines of at most 42 characters, two lines to a
 * cue, the utterance span split by character share and every cue, the last of an utterance
 * included, capped at 7000 ms. Lengths count UTF-16 code units, as the app's do, not code points.
 */
final class Captions
{
    public const MAX_CHARS = 42;

    public const MAX_LINES = 2;

    public const MAX_CUE_MS = 7000;

    private function __construct()
    {
    }

    /**
     * @param  list<Utterance>  $utterances
     * @return list<Cue>
     */
    public static function segment(array $utterances): array
    {
        $out = [];
        foreach ($utterances as $utterance) {
            $chunks = self::wrap($utterance->text);
            $span = max(0, $utterance->endMs - $utterance->startMs);
            $total = array_sum(array_map(Time::length(...), $chunks));
            $total = $total === 0 ? 1 : $total;
            $accumulated = 0;
            $last = count($chunks) - 1;
            foreach ($chunks as $index => $text) {
                $start = $utterance->startMs + Time::round($span * $accumulated / $total);
                $accumulated += max(1, Time::length($text));
                $end = $index === $last ? $utterance->endMs : $utterance->startMs + Time::round($span * $accumulated / $total);
                $end = min($end, $start + self::MAX_CUE_MS);
                $out[] = new Cue($start, max($end, $start), $utterance->speakerName, $text);
            }
        }

        return $out;
    }

    /**
     * @param  list<Utterance>  $utterances
     * @return list<Cue>
     */
    public static function utteranceCues(array $utterances): array
    {
        return array_map(static fn (Utterance $u): Cue => new Cue($u->startMs, $u->endMs, $u->speakerName, $u->text), $utterances);
    }

    /**
     * Word-wraps the text into cues of at most two lines of 42 characters; an overlong word keeps a
     * line to itself. Text without a word gives one empty cue.
     *
     * @return non-empty-list<string>
     */
    public static function wrap(string $text): array
    {
        $words = Time::words($text);
        if ($words === []) {
            return [''];
        }
        $cues = [];
        $lines = [];
        $line = '';
        $lineLength = 0;
        $flushLine = static function () use (&$lines, &$line, &$lineLength): void {
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = '';
            $lineLength = 0;
        };
        $flushCue = static function () use (&$cues, &$lines, $flushLine): void {
            $flushLine();
            if ($lines !== []) {
                $cues[] = implode("\n", $lines);
            }
            $lines = [];
        };
        foreach ($words as $word) {
            $wordLength = Time::length($word);
            $candidateLength = $line !== '' ? $lineLength + 1 + $wordLength : $wordLength;
            if ($candidateLength <= self::MAX_CHARS) {
                $line = $line !== '' ? $line.' '.$word : $word;
                $lineLength = $candidateLength;

                continue;
            }
            $flushLine();
            if (count($lines) >= self::MAX_LINES) {
                $flushCue();
            }
            $line = $word;
            $lineLength = $wordLength;
        }
        $flushCue();

        return $cues === [] ? [''] : $cues;
    }
}
