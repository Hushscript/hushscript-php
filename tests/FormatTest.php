<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Format\Captions;
use Hushscript\Format\Srt;
use Hushscript\Format\Text;
use Hushscript\Format\Time;
use Hushscript\Format\Vtt;
use Hushscript\Utterance;
use PHPUnit\Framework\TestCase;

/** The formatter rules the goldens pin, one at a time (spec 6.2). */
final class FormatTest extends TestCase
{
    private static function say(string $text, int $start = 0, int $end = 1000, string $speaker = 'A', string $name = 'Ann'): Utterance
    {
        return new Utterance($speaker, $name, $start, $end, $text);
    }

    public function testLinesAreCountedInUtf16UnitsNotCodePoints(): void
    {
        $word = str_repeat('😀', 14); // 14 code points, 28 UTF-16 units
        $line = $word.' '.$word; // 29 code points, 57 units

        self::assertSame(28, Time::length($word));
        self::assertSame([$word."\n".$word], Captions::wrap($line), 'the second word does not fit the first line: 28 + 1 + 28 > 42 units');
        self::assertSame([str_repeat('a', 20).' '.str_repeat('b', 21)], Captions::wrap(str_repeat('a', 20).' '.str_repeat('b', 21)), 'plain letters count once: 20 + 1 + 21 = 42 fits');
        self::assertSame("1\n00:00:00,000 --> 00:00:01,000\nAnn: ".$word."\n".$word, Srt::render([self::say($line)]));
    }

    public function testTheSpeakerPrefixIsAddedAfterWrappingAndDoesNotCountTowardTheLimit(): void
    {
        $text = str_repeat('x', 42);

        self::assertSame("1\n00:00:00,000 --> 00:00:01,000\nAnn: ".$text, Srt::render([self::say($text)]));
    }

    public function testTheLastCueOfAnUtteranceIsCappedAtSevenSeconds(): void
    {
        $cues = Captions::segment([self::say('short', 0, 20000)]);

        self::assertCount(1, $cues);
        self::assertSame([0, 7000], [$cues[0]->startMs, $cues[0]->endMs]);
    }

    public function testASpanIsSplitByCharacterShareAndRoundedHalfUp(): void
    {
        $line = str_repeat('a', 40);
        $cues = Captions::segment([self::say($line.' '.$line.' '.$line, 0, 3)]);

        self::assertCount(2, $cues);
        self::assertSame(2, $cues[1]->startMs, 'a share of 1.5 ms rounds up to 2');
        self::assertSame(2, Time::round(1.5));
        self::assertSame(3, Time::round(2.5));
        self::assertSame(0, Time::round(0.4999));
    }

    public function testJavaScriptWhitespaceSplitsWordsAndNothingElseDoes(): void
    {
        self::assertSame(['a', 'b', 'c'], Time::words("a\u{FEFF}b\u{A0}c"), 'U+FEFF and U+00A0 are whitespace to JavaScript');
        self::assertSame(["a\u{85}b", "c\0d"], Time::words("a\u{85}b c\0d"), 'U+0085 and NUL are not');
        self::assertSame('x', Time::strip("\u{FEFF} \t x \u{2028}"));
        self::assertSame("\0x\0", Time::strip("\0x\0"), 'NUL is not trimmed, as PHP trim() would');
    }

    public function testCueTextDropsBlankLinesAndALineOfOnlyWhitespace(): void
    {
        $out = Srt::render([self::say("one\r\n\r\n\u{FEFF}\n\ntwo")], captions: false);

        self::assertSame("1\n00:00:00,000 --> 00:00:01,000\nAnn: one\ntwo", $out);
    }

    public function testARunOfDashesBeforeAnArrowIsCut(): void
    {
        self::assertStringEndsWith('a -> b ->> c', Srt::render([self::say('a --> b --->> c')], captions: false));
    }

    public function testVttEscapesMarkupAndTheEndOfACue(): void
    {
        $out = Vtt::render([self::say('Tom & Jerry <b> --> x')], captions: false);

        self::assertSame("WEBVTT\n\n1\n00:00:00.000 --> 00:00:01.000\nAnn: Tom &amp; Jerry &lt;b> --&gt; x\n", $out);
    }

    public function testTextMergesConsecutiveSpeakersAndStampsHours(): void
    {
        $utterances = [self::say('one', 3_600_000, 3_601_000), self::say('two', 3_602_000, 3_603_000), self::say('three', 3_604_000, 3_605_000, 'B', 'Bob')];

        self::assertSame("Ann: one two\n\nBob: three", Text::render($utterances));
        self::assertSame("[1:00:00] Ann: one\n\n[1:00:02] Ann: two\n\n[1:00:04] Bob: three", Text::render($utterances, timestamps: true, merge: false));
        self::assertSame("one two\n\nthree", Text::render($utterances, speakers: false));
        self::assertSame('00:59:59,999', Time::stamp(3_599_999, ','));
        self::assertSame('0:05', Time::clock(5999));
    }

    public function testTextWithoutAnyWordIsOneEmptyCue(): void
    {
        self::assertSame([''], Captions::wrap(" \u{FEFF}\n"));
    }
}
