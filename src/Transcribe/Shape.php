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

    public static function bad(string $what): ApiError
    {
        return new ApiError('The API answered with '.$what.' the SDK cannot use.', 'bad_response', status: 200);
    }

    public static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 ? $value : null;
    }

    /** @return array<string, mixed> */
    public static function record(#[\SensitiveParameter] mixed $value, string $what): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw self::bad($what);
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /** An id that goes into a URL path: non-empty, not a dot segment, percent-encoded. */
    public static function segment(mixed $id, string $name = 'id'): string
    {
        if (! is_string($id) || $id === '' || $id === '.' || $id === '..' || preg_match('//u', $id) !== 1) {
            throw new PreflightError($name.' must be a non-empty string that is not "." or "..".', 'invalid_option');
        }

        return rawurlencode($id);
    }
}
