<?php

declare(strict_types=1);

namespace Hushscript\Format;

/**
 * The string and number rules of the app's exporters (spec 6.2) in PHP terms: JavaScript's
 * whitespace set, UTF-16 lengths and `Math.round`. The conformance goldens pin the output.
 */
final class Time
{
    /**
     * JavaScript's whitespace (what `\s` and `String.prototype.trim` use): it has U+FEFF and lacks U+0085
     * and the NUL and C0 separators that PHP's `trim()` strips.
     */
    public const SPACE = '\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const MS_PER_HOUR = 3_600_000;

    private const MS_PER_MINUTE = 60_000;

    private function __construct()
    {
    }

    /** Trims JavaScript whitespace from both ends. */
    public static function strip(string $text): string
    {
        return preg_replace('/^['.self::SPACE.']+|['.self::SPACE.']+$/u', '', $text) ?? $text;
    }

    /** @return list<string> the runs of text between JavaScript whitespace */
    public static function words(string $text): array
    {
        preg_match_all('/[^'.self::SPACE.']+/u', $text, $found);

        return $found[0];
    }

    /**
     * The length JavaScript reports for the text: UTF-16 code units, so a character outside the
     * basic plane (an emoji) counts as two. The text is UTF-8 that came out of a JSON body.
     */
    public static function length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** `Math.round` for a non-negative value (PHP's `round()` is not the same function on every version). */
    public static function round(float $value): int
    {
        $floor = floor($value);

        return (int) ($value - $floor >= 0.5 ? $floor + 1 : $floor);
    }

    /** `HH:MM:SS,mmm`, floored to whole milliseconds; VTT swaps the comma for a dot. */
    public static function stamp(int $milliseconds, string $separator): string
    {
        $total = max(0, $milliseconds);
        $hours = intdiv($total, self::MS_PER_HOUR);
        $rest = $total % self::MS_PER_HOUR;

        return sprintf('%02d:%02d:%02d%s%03d', $hours, intdiv($rest, self::MS_PER_MINUTE), intdiv($rest % self::MS_PER_MINUTE, 1000), $separator, $rest % 1000);
    }

    /** `M:SS` or `H:MM:SS`, floored to whole seconds. */
    public static function clock(int $milliseconds): string
    {
        $seconds = intdiv(max(0, $milliseconds), 1000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds % 60) : sprintf('%d:%02d', $minutes, $seconds % 60);
    }
}
