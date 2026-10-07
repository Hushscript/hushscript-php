<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Exception\DailyLimitError;
use Hushscript\Exception\FileLimitError;
use Hushscript\Exception\UnsupportedMediaError;

/**
 * The checks that run before any request that holds credit (spec 8.1): format, size, duration and
 * the account's daily limit.
 */
final class Preflight
{
    public const MAX_BYTES = 5_000_000_000;

    public const MIN_SECONDS = 15;

    public const MAX_SECONDS = 36_000;

    private function __construct()
    {
    }

    /**
     * @return array{int, int}  the size in bytes and the declared duration: the probe's ceil, so the hold never falls short of the file
     */
    public static function media(Source $source, Options $options): array
    {
        if (Sniff::format($source->head()) === null) {
            throw new UnsupportedMediaError('The file is not a supported audio or video format.');
        }
        if ($source->size < 1 || $source->size > self::MAX_BYTES) {
            throw new FileLimitError('The file is '.$source->size.' bytes; it must be between 1 byte and 5 GB.', 'size_out_of_range');
        }
        $seconds = $options->durationSeconds ?? Probe::seconds($source);
        // Compared as a float: casting 1e300 or 9.3e18 to int is undefined.
        if (! is_finite($seconds) || $seconds < self::MIN_SECONDS || ceil($seconds) > self::MAX_SECONDS) {
            throw new FileLimitError('The file is '.rtrim(rtrim(sprintf('%.3f', $seconds), '0'), '.').' seconds long; it must be between 15 seconds and 10 hours.', 'duration_out_of_range');
        }

        return [$source->size, (int) ceil($seconds)];
    }

    /**
     * Spec 8.1 step 5, from one `GET /v1/account`: the daily limit only. The balance is the server's call at create (402).
     *
     * @param  array<mixed>  $account
     */
    public static function account(#[\SensitiveParameter] array $account, int $durationSeconds): void
    {
        $limits = is_array($account['limits'] ?? null) ? $account['limits'] : [];
        $daily = self::whole($limits['max_daily_seconds'] ?? null);
        $used = self::whole($limits['used_today_seconds'] ?? null);
        if ($daily !== null && $used !== null && $used + $durationSeconds > $daily) {
            throw new DailyLimitError(
                'This upload would pass the daily transcription limit.',
                'daily_limit',
                details: ['used_today_seconds' => $used, 'max_daily_seconds' => $daily],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function createBody(int $size, int $durationSeconds, Options $options, #[\SensitiveParameter] string $key): array
    {
        $body = ['size_bytes' => $size, 'duration_seconds' => $durationSeconds, 'idempotency_key' => $key];
        if ($options->title !== null) {
            $body['title'] = $options->title;
        }
        if ($options->matterCode !== null) {
            $body['matter_code'] = $options->matterCode;
        }
        $wire = $options->transcriptionOptions();
        if ($wire !== []) {
            $body['transcription_options'] = $wire;
        }

        return $body;
    }

    private static function whole(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
