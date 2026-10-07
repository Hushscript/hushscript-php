<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Exception\ApiError;
use Hushscript\Exception\PreflightError;

/**
 * What a 2xx body has to look like before the pipeline acts on it (R52): anything else is `bad_response`.
 */
final class Shape
{
    private function __construct()
    {
    }

    /** An unusable 2xx body: `bad_response` with the status the API actually answered. */
    public static function bad(string $what, int $status = 200): ApiError
    {
        return new ApiError('The API answered '.$status.' with '.$what.' the SDK cannot use.', 'bad_response', status: $status);
    }

    public static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 ? $value : null;
    }

    /**
     * Whether `segment()` accepts the id: what an id the server sends has to be before the SDK builds a path from it (R53).
     */
    public static function usable(string $id): bool
    {
        return $id !== '' && $id !== '.' && $id !== '..' && preg_match('//u', $id) === 1 && strpbrk($id, '/\\') === false;
    }

    /**
     * An id that goes into a URL path as one segment: non-empty, valid UTF-8, not a dot segment and without a
     * slash or backslash (which a client or proxy may treat as a separator once decoded), percent-encoded.
     */
    public static function segment(mixed $id, string $name = 'id'): string
    {
        if (! is_string($id) || ! self::usable($id)) {
            throw new PreflightError($name.' must be a non-empty string without a slash that is not "." or "..".', 'invalid_option');
        }

        return rawurlencode($id);
    }
}
