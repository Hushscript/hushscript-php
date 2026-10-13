<?php

declare(strict_types=1);

namespace Hushscript\Format;

use Hushscript\Utterance;

final class Text
{
    private function __construct()
    {
    }

    /**
     * "Name: text" blocks joined by a blank line. A `\r` and whitespace-only lines are kept as they
     * come, as in the app.
     *
     * @param  list<Utterance>  $utterances
     */
    public static function render(array $utterances, bool $speakers = true, bool $timestamps = false, bool $merge = true): string
    {
        $blocks = [];
        foreach ($utterances as $utterance) {
            $last = count($blocks) - 1;
            if ($merge && $last >= 0 && $blocks[$last]['speaker'] === $utterance->speaker) {
                $blocks[$last]['text'] .= ' '.$utterance->text;

                continue;
            }
            $blocks[] = ['speaker' => $utterance->speaker, 'name' => $utterance->speakerName, 'start' => $utterance->startMs, 'text' => $utterance->text];
        }
        $out = [];
        foreach ($blocks as $block) {
            $prefix = $timestamps ? '['.Time::clock($block['start']).'] ' : '';
            $who = $speakers ? $block['name'] : '';
            $out[] = $who !== '' ? $prefix.$who.': '.$block['text'] : $prefix.$block['text'];
        }

        return implode("\n\n", $out);
    }
}
