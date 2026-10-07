<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

/** Where the conformance checkout lives, for the tests that read its fixtures. */
final class Conformance
{
    public static function dir(): string
    {
        $dir = getenv('CONFORMANCE_DIR');
        if (! is_string($dir) || $dir === '' || ! is_file($dir.'/fixtures/hint-rendering.json')) {
            throw new \RuntimeException('CONFORMANCE_DIR must point at a hushscript-sdk-conformance checkout (just test sets it).');
        }

        return $dir;
    }

    /** @return array<mixed> */
    public static function json(string $relative): array
    {
        $decoded = json_decode((string) file_get_contents(self::dir().'/'.$relative), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \RuntimeException("{$relative} is not a JSON object or array");
        }

        return $decoded;
    }
}
