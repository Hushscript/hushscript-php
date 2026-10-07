<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Generated\Hints;

/**
 * Spec 9.3 rendering: split after each ". ", drop the sentences with an unfilled placeholder, join
 * the rest with a space; nothing left is null.
 */
final class Hint
{
    private function __construct()
    {
    }

    /**
     * @param  array<string, mixed>  $details  the API's extra body keys, snake_case
     */
    public static function render(string $code, array $details = [], ?int $retryAfter = null, ?string $scope = null): ?string
    {
        $template = Hints::TABLE[$code] ?? null;
        if ($template === null) {
            return null;
        }
        $context = self::context($details, $retryAfter, $scope);
        $kept = [];
        $sentences = preg_split('/(?<=\.) /', $template);
        foreach ($sentences === false ? [] : $sentences as $sentence) {
            preg_match_all('/\{(\w+)\}/', $sentence, $found);
            foreach ($found[1] as $name) {
                if (! array_key_exists($name, $context)) {
                    continue 2;
                }
            }
            $kept[] = preg_replace_callback(
                '/\{(\w+)\}/',
                static fn (array $match): string => $context[$match[1]] ?? '',
                $sentence,
            ) ?? '';
        }

        return $kept === [] ? null : implode(' ', $kept);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    private static function context(array $details, ?int $retryAfter, ?string $scope): array
    {
        $context = [];
        foreach ($details as $key => $value) {
            $context[self::camel($key)] = self::format($value);
        }
        $derived = [
            'needed' => self::minutes($details, ['needed_seconds'], true),
            'balance' => self::minutes($details, ['balance_seconds'], false),
            'used' => self::minutes($details, ['used_seconds', 'used_today_seconds'], false),
            'max' => self::minutes($details, ['max_seconds', 'max_daily_seconds'], false),
            'retryAfter' => self::wholeSeconds($retryAfter, $details['retry_after_seconds'] ?? null),
            'scope' => $scope,
        ];
        foreach ($derived as $name => $value) {
            if ($value !== null) {
                $context[$name] = $value;
            }
        }

        return $context;
    }

    private static function camel(string $key): string
    {
        $parts = explode('_', $key);
        $head = array_shift($parts);

        return $head.implode('', array_map(ucfirst(...), $parts));
    }

    private static function format(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
        }
        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
        }

        return is_string($value) ? $value : '';
    }

    /** A finite number a whole count of seconds can come from; anything else (NaN, infinity, beyond int64) counts as absent. */
    private static function number(mixed $value): int|float|null
    {
        if (is_int($value)) {
            return $value;
        }

        return is_float($value) && is_finite($value) && abs($value) < 9.2e18 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $keys
     */
    private static function minutes(array $details, array $keys, bool $up): ?string
    {
        foreach ($keys as $key) {
            $seconds = self::number($details[$key] ?? null);
            if ($seconds !== null) {
                return (string) (int) ($up ? ceil($seconds / 60) : floor($seconds / 60));
            }
        }

        return null;
    }

    private static function wholeSeconds(?int $retryAfter, mixed $fromDetails): ?string
    {
        $seconds = $retryAfter ?? self::number($fromDetails);

        return $seconds === null ? null : (string) (int) ceil($seconds);
    }
}
