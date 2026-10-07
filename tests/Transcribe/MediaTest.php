<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\FileLimitError;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\UnsupportedMediaError;
use Hushscript\Tests\Support\Conformance;
use Hushscript\Transcribe\Options;
use Hushscript\Transcribe\Preflight;
use Hushscript\Transcribe\Probe;
use Hushscript\Transcribe\Sniff;
use Hushscript\Transcribe\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Format by magic bytes and the duration probe, against the conformance media fixtures (spec 8.1, 8.2). */
final class MediaTest extends TestCase
{
    private const REFUSED = ['webm-renamed.mp3'];

    private const FORMATS = ['mp3' => Sniff::MP3, 'm4a' => Sniff::ISOBMFF, 'mp4' => Sniff::ISOBMFF, 'wav' => Sniff::WAV, 'flac' => Sniff::FLAC, 'ogg' => Sniff::OGG, 'opus' => Sniff::OGG];

    /** @return iterable<string, array{string, float}> */
    public static function fixtures(): iterable
    {
        foreach (Conformance::json('fixtures/media/durations.json') as $file => $seconds) {
            if (is_float($seconds) || is_int($seconds)) {
                yield $file => [(string) $file, $seconds];
            }
        }
    }

    /** @return iterable<string, array{string, float}> */
    public static function probed(): iterable
    {
        foreach (self::fixtures() as $name => $case) {
            if (! in_array($case[0], self::REFUSED, true)) {
                yield $name => $case;
            }
        }
    }

    private static function path(string $file): string
    {
        return Conformance::dir().'/fixtures/media/'.$file;
    }

    #[DataProvider('probed')]
    public function testEveryFixtureIsSniffedByItsBytes(string $file): void
    {
        $extension = substr($file, (int) strrpos($file, '.') + 1);

        self::assertSame(self::FORMATS[$extension], Sniff::format(Source::open(self::path($file))->head()));
    }

    public function testAWebmFileRenamedToMp3IsRefusedByTheSniffer(): void
    {
        self::assertNull(Sniff::format(Source::open(self::path('webm-renamed.mp3'))->head()));
    }

    public function testAWebmFileIsRefusedBeforeAnythingIsProbed(): void
    {
        $this->expectException(UnsupportedMediaError::class);
        $this->expectExceptionMessage('not a supported audio or video format');

        Preflight::media(Source::open(self::path('webm-renamed.mp3')), Options::forTranscribe([]));
    }

    public function testTheSnifferNeverThrowsOnArbitraryBytes(): void
    {
        mt_srand(17);
        $known = [Sniff::MP3, Sniff::ISOBMFF, Sniff::WAV, Sniff::FLAC, Sniff::OGG, null];
        for ($round = 0; $round < 300; $round++) {
            $bytes = '';
            for ($index = 0, $length = mt_rand(0, 40); $index < $length; $index++) {
                $bytes .= chr(mt_rand(0, 255));
            }

            self::assertContains(Sniff::format($bytes), $known);
        }
        self::assertNull(Sniff::format(''));
        self::assertNull(Sniff::format('RIFF'));
    }

    #[DataProvider('probed')]
    public function testTheProbeIsNeverUnderTheTrueDurationAndWithinASecondOver(string $file, float $true): void
    {
        $probe = Probe::seconds(Source::open(self::path($file)));

        self::assertGreaterThanOrEqual($true - 0.0006, $probe, "{$file} is probed under its duration");
        self::assertGreaterThanOrEqual($true, ceil($probe));
        self::assertLessThanOrEqual(1.0, $probe - $true, "{$file} is probed more than a second over");
    }

    public function testAVbrMp3WithoutAXingHeaderIsCountedFrameByFrame(): void
    {
        // 100 MPEG-1 Layer III frames of 417 bytes, 128 kbit/s at 44.1 kHz: 100 x 1152 / 44100 s.
        $path = $this->temp(str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 413), 100));

        self::assertEqualsWithDelta(2.6122, Probe::seconds(Source::open($path)), 0.001);
    }

    public function testAnUnreadableDurationNeedsTheCallerToPassIt(): void
    {
        $path = $this->temp('ID3'.str_repeat("\x00", 200));

        try {
            Probe::seconds(Source::open($path));
            self::fail('expected an UnsupportedMediaError');
        } catch (UnsupportedMediaError $error) {
            self::assertSame('duration_unreadable', $error->errorCode);
            self::assertStringContainsString('pass duration_seconds', $error->getMessage());
        }
    }

    public function testATruncatedFlacIsReadFromItsHeaderAndStillWithinTheLimits(): void
    {
        $seconds = Probe::seconds(Source::open(self::path('truncated.flac')));

        self::assertGreaterThan(0.0, $seconds);
    }

    public function testATenSecondFileIsRefusedAsTooShort(): void
    {
        try {
            Preflight::media(Source::open(self::path('short-10s.mp3')), Options::forTranscribe([]));
            self::fail('expected a FileLimitError');
        } catch (FileLimitError $error) {
            self::assertSame('duration_out_of_range', $error->errorCode);
            self::assertNull($error->status);
        }
    }

    public function testTheDeclaredDurationIsTheCeilOfTheProbe(): void
    {
        [$size, $declared] = Preflight::media(Source::open(self::path('cbr-20s.mp3')), Options::forTranscribe([]));

        self::assertSame((int) filesize(self::path('cbr-20s.mp3')), $size);
        self::assertSame(21, $declared);
    }

    public function testAGivenDurationReplacesTheProbe(): void
    {
        [, $declared] = Preflight::media(Source::open(self::path('cbr-20s.mp3')), Options::forTranscribe(['duration_seconds' => 40.2]));

        self::assertSame(41, $declared);
    }

    /** @return iterable<string, array{float|int}> */
    public static function outOfRangeDurations(): iterable
    {
        yield 'under the floor' => [14.99];
        yield 'over the ceiling' => [36000.5];
    }

    #[DataProvider('outOfRangeDurations')]
    public function testAFiniteDurationOutsideTheRangeIsAFileLimit(float|int $seconds): void
    {
        $this->expectException(FileLimitError::class);

        Preflight::media(Source::open(self::path('cbr-20s.mp3')), Options::forTranscribe(['duration_seconds' => $seconds]));
    }

    public function testTheLimitsAreInclusive(): void
    {
        [, $low] = Preflight::media(Source::open(self::path('cbr-20s.mp3')), Options::forTranscribe(['duration_seconds' => 15]));
        [, $high] = Preflight::media(Source::open(self::path('cbr-20s.mp3')), Options::forTranscribe(['duration_seconds' => 36000]));

        self::assertSame([15, 36000], [$low, $high]);
    }

    public function testAnEmptyFileIsNotAMediaFile(): void
    {
        $this->expectException(UnsupportedMediaError::class);

        Preflight::media(Source::open($this->temp('')), Options::forTranscribe([]));
    }

    public function testAMissingPathIsFileUnreadableWithTheOsErrorAsTheCause(): void
    {
        try {
            Source::open('/nonexistent/dir/file.mp3');
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('file_unreadable', $error->errorCode);
            self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            self::assertStringContainsString('No such file or directory', $error->getPrevious()->getMessage());
        }
    }

    public function testADirectoryAndAnEmptyPathAreFileUnreadable(): void
    {
        foreach ([sys_get_temp_dir(), '', "a\0b"] as $path) {
            try {
                Source::open($path);
                self::fail('expected a PreflightError for '.json_encode($path));
            } catch (PreflightError $error) {
                self::assertSame('file_unreadable', $error->errorCode);
            }
        }
    }

    public function testAFileThatCannotBeOpenedIsFileUnreadable(): void
    {
        $path = $this->temp('x');
        chmod($path, 0000);
        if (is_readable($path)) {
            self::markTestSkipped('The test runs as a user that reads everything.');
        }

        $this->expectException(PreflightError::class);
        $this->expectExceptionMessage('Permission denied');

        Source::open($path);
    }

    public function testRangesAreReadFromTheFileWithoutHoldingItWhole(): void
    {
        $bytes = random_bytes(5000);
        $source = Source::open($this->temp($bytes));

        self::assertSame(5000, $source->size);
        self::assertSame(substr($bytes, 100, 1000), $source->readRange(100, 1000));
        self::assertSame(substr($bytes, 4000), $source->readRange(4000, 1000));
        self::assertSame('', $source->readRange(0, 0));
        self::assertSame(substr($bytes, 0, 4096), $source->head());
    }

    public function testAFileThatShrinksWhileItUploadsIsASourceReadFailure(): void
    {
        $path = $this->temp(str_repeat('a', 100));
        $source = Source::open($path);
        file_put_contents($path, 'short');

        try {
            $source->readRange(0, 100);
            self::fail('expected an UploadError');
        } catch (\Hushscript\Exception\UploadError $error) {
            self::assertSame('source_read_failed', $error->errorCode);
            self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
        }
    }

    public function testAFileThatVanishesWhileItUploadsIsASourceReadFailure(): void
    {
        $path = $this->temp('abc');
        $source = Source::open($path);
        unlink($path);

        $this->expectException(\Hushscript\Exception\UploadError::class);

        $source->readRange(0, 3);
    }

    public function testSizeOfOnlyAnswersForARegularFile(): void
    {
        self::assertSame(3, Source::sizeOf($this->temp('abc')));
        self::assertNull(Source::sizeOf('/nonexistent'));
        self::assertNull(Source::sizeOf(sys_get_temp_dir()));
        self::assertNull(Source::sizeOf(''));
    }

    private function temp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hs');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @chmod($path, 0600);
            @unlink($path);
        }
    }

    public function testAFifoIsFileUnreadableWithoutBlocking(): void
    {
        $fifo = sys_get_temp_dir().'/hushscript-fifo-'.bin2hex(random_bytes(4));
        exec('mkfifo '.escapeshellarg($fifo).' 2>/dev/null', $ignored, $status);
        if ($status !== 0) {
            self::markTestSkipped('mkfifo is not available.');
        }
        // Run in a child that is killed after 10 s: a regression would block in open() for good, and must fail instead of hanging.
        $script = 'require $argv[1]; try { Hushscript\\Transcribe\\Source::open($argv[2]); echo "opened"; } catch (Hushscript\\Exception\\PreflightError $e) { echo $e->errorCode, "|", $e->getMessage(); }';
        $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $fifo], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            stream_set_blocking($pipes[1], false);
            $deadline = microtime(true) + 10.0;
            while (microtime(true) < $deadline && (proc_get_status($process)['running'])) {
                usleep(20000);
            }
            $finished = ! proc_get_status($process)['running'];
            $output = (string) stream_get_contents($pipes[1]);
            if (! $finished) {
                proc_terminate($process, 9);
            }
            self::assertTrue($finished, 'Source::open blocked on a FIFO');
            self::assertStringStartsWith('file_unreadable|', $output);
            self::assertStringContainsString('not a regular file', $output);
        } finally {
            proc_close($process);
            unlink($fifo);
        }
    }

    public function testALargeJunkTailIsNotScannedByteByByte(): void
    {
        $frames = (string) file_get_contents(self::path('cbr-20s.mp3'), false, null, 0, 40000);
        $path = $this->temp('');
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        fwrite($handle, $frames);
        $block = str_repeat("\0", 1 << 20);
        for ($megabytes = 0; $megabytes < 24; $megabytes++) {
            fwrite($handle, $block);
        }
        fclose($handle);

        $started = hrtime(true);
        $seconds = Probe::seconds(Source::open($path));
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertGreaterThan(1.0, $seconds, 'the frames before the junk still count');
        self::assertLessThan(8.0, $elapsed, 'the junk tail is given up on after a bounded run, not walked to the end');
    }

    /** A well-formed ID3v2.4 tag of `$bytes` bytes in all. */
    private static function tag(int $bytes): string
    {
        $size = $bytes - 10;
        $syncsafe = chr(($size >> 21) & 0x7F).chr(($size >> 14) & 0x7F).chr(($size >> 7) & 0x7F).chr($size & 0x7F);

        return "ID3\x04\x00\x00".$syncsafe.str_repeat("\0", $size);
    }

    /** @return iterable<string, array{int}> */
    public static function midFileTags(): iterable
    {
        yield '70 KB tag' => [70 * 1024];
        yield '300 KB tag' => [300 * 1024];
    }

    #[DataProvider('midFileTags')]
    public function testAnId3TagBetweenTwoFilesIsSkippedByItsSizeAndNothingAfterItIsLost(int $bytes): void
    {
        $first = self::path('cbr-20s.mp3');
        $second = self::path('vbr-xing-61s.mp3');
        $sum = Probe::seconds(Source::open($first)) + Probe::seconds(Source::open($second));
        $path = $this->temp('');
        file_put_contents($path, (string) file_get_contents($first).self::tag($bytes).(string) file_get_contents($second));

        $seconds = Probe::seconds(Source::open($path));

        self::assertGreaterThanOrEqual($sum - 0.001, $seconds, 'never under the sum of the two parts');
        self::assertLessThan($sum + 1.0, $seconds);
    }

    public function testAnId3LookalikeThatClaimsMoreBytesThanTheFileHasSkipsNothing(): void
    {
        $first = self::path('cbr-20s.mp3');
        $second = self::path('cbr-61s.mp3');
        $sum = Probe::seconds(Source::open($first)) + Probe::seconds(Source::open($second));
        $liar = "ID3\x04\x00\x00\x7F\x7F\x7F\x7F";
        $path = $this->temp('');
        file_put_contents($path, (string) file_get_contents($first).$liar.(string) file_get_contents($second));

        self::assertGreaterThanOrEqual($sum - 0.001, Probe::seconds(Source::open($path)));
    }

    public function testATagFollowedByMoreThanABlockOfJunkBeforeTheFramesNeverCountsLessThanTheFrames(): void
    {
        // The junk ends inside a 1 MiB block, so a frame skip crosses the next block boundary.
        $frames = (string) file_get_contents(self::path('cbr-20s.mp3'));
        $true = Probe::seconds(Source::open(self::path('cbr-20s.mp3')));
        $path = $this->temp('');
        file_put_contents($path, self::tag(1000).str_repeat("\0", 2_000_000).$frames);

        self::assertGreaterThanOrEqual($true - 0.001, Probe::seconds(Source::open($path)), 'a failed tag skip loses no frame');
    }

    public function testJunkOfAnyLengthBetweenFramesNeverHidesTheFramesAfterIt(): void
    {
        $first = self::path('cbr-20s.mp3');
        $second = self::path('cbr-20s.mp3');
        $sum = Probe::seconds(Source::open($first)) * 2;
        $path = $this->temp('');
        file_put_contents($path, (string) file_get_contents($first).str_repeat("\0", 3 * (1 << 20) + 17).(string) file_get_contents($second));

        self::assertGreaterThanOrEqual($sum - 0.001, Probe::seconds(Source::open($path)), 'there is no give-up point inside the file');
    }
}
