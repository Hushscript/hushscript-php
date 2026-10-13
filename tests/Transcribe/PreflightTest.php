<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\DailyLimitError;
use Hushscript\Exception\FileLimitError;
use Hushscript\Tests\Support\Conformance;
use Hushscript\Transcribe\Options;
use Hushscript\Transcribe\Preflight;
use Hushscript\Transcribe\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreflightTest extends TestCase
{
    /** A real MP3 that declares the size given, so the size check sees it. */
    private static function declared(int $size): Source
    {
        $stream = fopen(Conformance::dir().'/fixtures/media/cbr-20s.mp3', 'rb');
        self::assertIsResource($stream);

        return Source::stream($stream, $size);
    }

    public function testASizeOutsideTheRangeNamesTheSize(): void
    {
        try {
            Preflight::media(self::declared(5_000_000_001), Options::forTranscribe(['duration_seconds' => 20]));
            self::fail('expected a FileLimitError');
        } catch (FileLimitError $error) {
            self::assertSame('size_out_of_range', $error->errorCode);
            self::assertSame('The file is 5000000001 bytes; it must be between 1 byte and 5 GB.', $error->getMessage());
        }
    }

    /** @return iterable<string, array{float|int, string}> */
    public static function durations(): iterable
    {
        yield 'fraction' => [14.99, '14.99'];
        yield 'whole' => [10, '10'];
        yield 'half' => [36000.5, '36000.5'];
    }

    #[DataProvider('durations')]
    public function testADurationOutsideTheRangeNamesTheDurationWithoutTrailingZeros(float|int $seconds, string $shown): void
    {
        try {
            Preflight::media(self::declared(1000), Options::forTranscribe(['duration_seconds' => $seconds]));
            self::fail('expected a FileLimitError');
        } catch (FileLimitError $error) {
            self::assertSame('duration_out_of_range', $error->errorCode);
            self::assertSame('The file is '.$shown.' seconds long; it must be between 15 seconds and 10 hours.', $error->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function partialLimits(): iterable
    {
        yield 'no cap, only usage' => [['limits' => ['used_today_seconds' => 100]]];
        yield 'only a cap below the upload' => [['limits' => ['max_daily_seconds' => 10]]];
        yield 'no limits' => [[]];
        yield 'limits not an object' => [['limits' => 5]];
    }

    /** @param array<string, mixed> $account */
    #[DataProvider('partialLimits')]
    public function testADailyLimitIsCheckedOnlyWhenTheCapAndTheUsageAreBothKnown(array $account): void
    {
        Preflight::account($account, 20);

        $this->addToAssertionCount(1);
    }

    public function testAnUploadThatReachesTheCapExactlyPassesAndOneSecondMoreDoesNot(): void
    {
        Preflight::account(['limits' => ['max_daily_seconds' => 120, 'used_today_seconds' => 100]], 20);

        try {
            Preflight::account(['limits' => ['max_daily_seconds' => 120, 'used_today_seconds' => 100]], 21);
            self::fail('expected a DailyLimitError');
        } catch (DailyLimitError $error) {
            self::assertSame('daily_limit', $error->errorCode);
            self::assertSame(['used_today_seconds' => 100, 'max_daily_seconds' => 120], $error->details);
        }
    }
}
