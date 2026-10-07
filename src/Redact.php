<?php

declare(strict_types=1);

namespace Hushscript;

/**
 * Spec 10: text that may reach a caller never carries a token or a signed URL query.
 */
final class Redact
{
    public const MARKER = 'hsr…[redacted]';

    public const QUERY_MARKER = '…[redacted]';

    private const TOKEN_PATTERN = '/hsr\d+:[a-z]+:pat\.[0-9a-f]+\.[0-9a-f]+/';

    // The query of a presigned storage URL is a signature, so no text the SDK shows keeps one.
    private const URL_QUERY_PATTERN = '~(https?://[^\s"\'<>?#]+)\?[^\s"\'<>#]*~';

    private function __construct() {}

    public static function text(string $text): string
    {
        $text = preg_replace(self::TOKEN_PATTERN, self::MARKER, $text) ?? self::MARKER;

        return preg_replace(self::URL_QUERY_PATTERN, '$1?'.self::QUERY_MARKER, $text) ?? self::MARKER;
    }

    public static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[is_string($key) ? self::text($key) : $key] = self::value($item);
            }

            return $out;
        }

        return $value;
    }
}
