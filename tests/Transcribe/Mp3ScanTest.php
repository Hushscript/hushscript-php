<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Tests\Support\Deadline;
use Hushscript\Tests\Support\ScriptedStream;
use Hushscript\Transcribe\Mp3Scan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The frame walk on its own, not through Probe::seconds(), which takes the larger of it and getID3 and
 * so hides a walk that counts too little. Every frame body repeats its own header: a walk that steps by
 * any length other than the frame's, or reads a tag's payload as audio, finds those headers and counts
 * more than the file holds, so every total is asserted exactly.
 */
final class Mp3ScanTest extends TestCase
{
    /** MPEG-1 Layer III, 128 kbit/s, 44.1 kHz, no padding: 417 bytes, 1152 samples. */
    private const H128 = "\xFF\xFB\x90\x00";

    private const F128 = 1152 / 44100;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        // A regression that loops forever fails the test instead of hanging the run.
        Deadline::arm();
    }

    protected function tearDown(): void
    {
        Deadline::disarm();
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    private static function frames(string $header, int $length, int $count): string
    {
        return str_repeat(substr(str_repeat($header, intdiv($length, 4) + 1), 0, $length), $count);
    }

    private static function f128(int $count): string
    {
        return self::frames(self::H128, 417, $count);
    }

    private static function syncsafe(int $size): string
    {
        return chr(($size >> 21) & 0x7F).chr(($size >> 14) & 0x7F).chr(($size >> 7) & 0x7F).chr($size & 0x7F);
    }

    /** An ID3v2.4 tag around `$payload`, with `$flags` in its header. */
    private static function tag(string $payload, int $flags = 0): string
    {
        $tag = "ID3\x04\x00".chr($flags).self::syncsafe(strlen($payload)).$payload;

        return ($flags & 0x10) !== 0 ? $tag."3DI\x04\x00".chr($flags).self::syncsafe(strlen($payload)) : $tag;
    }

    private function scan(string $bytes): ?float
    {
        $path = tempnam(sys_get_temp_dir(), 'hs-mp3');
        self::assertNotFalse($path);
        $this->tempFiles[] = $path;
        file_put_contents($path, $bytes);

        return self::scanHandle($path);
    }

    private static function scanHandle(string $path): ?float
    {
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        try {
            return Mp3Scan::seconds($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @return iterable<string, array{string, int, int, int}> */
    public static function layouts(): iterable
    {
        yield 'MPEG-1 128 kbit/s 44.1 kHz' => [self::H128, 417, 1152, 44100];
        yield 'MPEG-1 128 kbit/s 44.1 kHz padded' => ["\xFF\xFB\x92\x00", 418, 1152, 44100];
        yield 'MPEG-1 128 kbit/s 44.1 kHz private bit' => ["\xFF\xFB\x91\x00", 417, 1152, 44100];
        yield 'MPEG-1 320 kbit/s 44.1 kHz' => ["\xFF\xFB\xE0\x00", 1044, 1152, 44100];
        yield 'MPEG-1 320 kbit/s 44.1 kHz padded' => ["\xFF\xFB\xE2\x00", 1045, 1152, 44100];
        yield 'MPEG-1 32 kbit/s 44.1 kHz' => ["\xFF\xFB\x10\x00", 104, 1152, 44100];
        yield 'MPEG-1 128 kbit/s 48 kHz' => ["\xFF\xFB\x94\x00", 384, 1152, 48000];
        yield 'MPEG-1 128 kbit/s 32 kHz' => ["\xFF\xFB\x98\x00", 576, 1152, 32000];
        yield 'MPEG-2 64 kbit/s 22.05 kHz' => ["\xFF\xF3\x80\x00", 208, 576, 22050];
        yield 'MPEG-2 64 kbit/s 24 kHz' => ["\xFF\xF3\x84\x00", 192, 576, 24000];
        yield 'MPEG-2 64 kbit/s 16 kHz' => ["\xFF\xF3\x88\x00", 288, 576, 16000];
        yield 'MPEG-2 160 kbit/s 22.05 kHz' => ["\xFF\xF3\xE0\x00", 522, 576, 22050];
        yield 'MPEG-2.5 32 kbit/s 11.025 kHz' => ["\xFF\xE3\x40\x00", 208, 576, 11025];
        yield 'MPEG-2.5 8 kbit/s 12 kHz' => ["\xFF\xE3\x14\x00", 48, 576, 12000];
        yield 'MPEG-2.5 8 kbit/s 8 kHz' => ["\xFF\xE3\x18\x00", 72, 576, 8000];
    }

    #[DataProvider('layouts')]
    public function testEveryLayoutIsWalkedFrameByFrameAtItsOwnLength(string $header, int $length, int $samples, int $rate): void
    {
        // A tag right after the frames is reached only by a walk that steps exactly: one that lands short
        // of it searches on into the tag and counts the frames inside it.
        $bytes = self::frames($header, $length, 3).self::tag(self::frames($header, $length, 2)).self::frames($header, $length, 2);

        self::assertEqualsWithDelta(5 * $samples / $rate, $this->scan($bytes), 1e-9);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHeaders(): iterable
    {
        yield 'no sync byte' => ["\x00\xFB\x90\x00"];
        yield 'reserved version' => ["\xFF\xEB\x90\x00"];
        yield 'Layer II' => ["\xFF\xFD\x90\x00"];
        yield 'free bitrate' => ["\xFF\xFB\x00\x00"];
        yield 'bad bitrate' => ["\xFF\xFB\xF0\x00"];
        yield 'reserved sample rate' => ["\xFF\xFB\x9C\x00"];
    }

    #[DataProvider('invalidHeaders')]
    public function testAnInvalidFrameHeaderIsNotCounted(string $header): void
    {
        $bytes = self::f128(2).$header.str_repeat("\0", 413).self::f128(2);

        self::assertEqualsWithDelta(4 * self::F128, $this->scan($bytes), 1e-9);
    }

    public function testAFileWithoutFramesHasNoDuration(): void
    {
        self::assertNull($this->scan(str_repeat("\0", 5000)));
        self::assertNull($this->scan(''));
    }

    public function testASingleFrameIsCounted(): void
    {
        self::assertEqualsWithDelta(self::F128, $this->scan(self::f128(1)), 1e-12);
    }

    public function testJunkBeforeTheFirstFrameIsSearchedFromTheStart(): void
    {
        self::assertEqualsWithDelta(3 * self::F128, $this->scan(str_repeat("\x55", 7).self::f128(3)), 1e-9);
    }

    public function testAHeaderInTheLastFourBytesCountsItsFrame(): void
    {
        self::assertEqualsWithDelta(4 * self::F128, $this->scan(self::f128(3).self::H128), 1e-9);
    }

    public function testAFewJunkBytesAfterTheLastFrameEndTheWalk(): void
    {
        self::assertEqualsWithDelta(3 * self::F128, $this->scan(self::f128(3)."\x01\x02\x03\x04\x05"), 1e-9);
    }

    public function testAFrameHeaderThatStraddlesTwoReadBlocksIsCounted(): void
    {
        // 236 bytes of junk put frame 2514's header at 1 MiB - 2: two of its bytes in each block.
        // The tag at the end is reached only by a walk still in step after the boundary.
        self::assertEqualsWithDelta(2600 * self::F128, $this->scan(str_repeat("\0", 236).self::f128(2600).self::tag(self::f128(3))), 1e-6);
    }

    public function testATagAtTheStartIsSkippedByItsSize(): void
    {
        self::assertEqualsWithDelta(2 * self::F128, $this->scan(self::tag(self::f128(3)).self::f128(2).self::tag(self::f128(3))), 1e-9);
    }

    public function testATagOfMoreThanABlockIsSkippedByAllFourSizeBytes(): void
    {
        // 5100 frames: a size of 2,126,700 bytes sets every syncsafe byte.
        self::assertEqualsWithDelta(2 * self::F128, $this->scan(self::tag(self::f128(5100)).self::f128(2)), 1e-9);
    }

    public function testATagWithAFooterIsSkippedWithIt(): void
    {
        self::assertEqualsWithDelta(2 * self::F128, $this->scan(self::tag(self::f128(3), 0x10).self::f128(2)), 1e-9);
    }

    public function testFlagsOtherThanTheFooterAddNothingToTheTag(): void
    {
        self::assertEqualsWithDelta(2 * self::F128, $this->scan(self::tag(self::f128(3), 0xEF).self::f128(2)), 1e-9);
    }

    /** @return iterable<string, array{string}> */
    public static function notTags(): iterable
    {
        yield 'version 0xFF' => ["ID3\xFF\x00\x00"];
        yield 'revision 0xFF' => ["ID3\x04\xFF\x00"];
    }

    #[DataProvider('notTags')]
    public function testAnId3HeaderWithAnInvalidVersionIsNotATag(string $head): void
    {
        $payload = self::f128(3);

        self::assertEqualsWithDelta(5 * self::F128, $this->scan($head.self::syncsafe(strlen($payload)).$payload.self::f128(2)), 1e-9);
    }

    public function testASizeByteWithItsHighBitSetIsNotATag(): void
    {
        // Read as a size, 0x80 would make the tag 128 bytes: exactly up to the frames after it.
        $small = self::frames("\xFF\xFB\x14\x00", 96, 1);
        $bytes = "ID3\x04\x00\x00\x00\x00\x00\x80".$small.str_repeat("\0", 32).self::f128(2);

        self::assertEqualsWithDelta(1152 / 48000 + 2 * self::F128, $this->scan($bytes), 1e-9);
    }

    public function testATagThatEndsTheFileIsSkipped(): void
    {
        self::assertEqualsWithDelta(2 * self::F128, $this->scan(self::f128(2).self::tag(self::f128(3))), 1e-9);
    }

    public function testATagBetweenFramesIsSkippedAndTheFramesAfterItCount(): void
    {
        // The walk resumes at the first frame after the tag: one a byte off would reach the last tag out of step.
        self::assertEqualsWithDelta(4 * self::F128, $this->scan(self::f128(2).self::tag(self::f128(3)).self::f128(2).self::tag(self::f128(3))), 1e-9);
    }

    public function testATagThatIsNotFollowedByAFrameIsSearchedLikeAudio(): void
    {
        $bytes = self::f128(2).self::tag(self::f128(3)).str_repeat("\0", 100).self::f128(2);

        self::assertEqualsWithDelta(7 * self::F128, $this->scan($bytes), 1e-9);
    }

    public function testAnId3LookalikeThatClaimsMoreBytesThanTheFileHasSkipsNothing(): void
    {
        $bytes = self::f128(2)."ID3\x04\x00\x00\x7F\x7F\x7F\x7F".self::f128(2);

        self::assertEqualsWithDelta(4 * self::F128, $this->scan($bytes), 1e-9);
    }

    public function testTheWalkReadsTheFileInBlocksNotWhole(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hs-mp3');
        self::assertNotFalse($path);
        $this->tempFiles[] = $path;
        file_put_contents($path, self::f128(10).str_repeat("\0", 16 << 20));
        memory_reset_peak_usage();
        $before = memory_get_usage();

        self::assertEqualsWithDelta(10 * self::F128, self::scanHandle($path), 1e-9);
        self::assertLessThan(8 << 20, memory_get_peak_usage() - $before, 'a 16 MiB file is not held whole');
    }

    public function testAReadThatFailsEndsTheWalkWithTheFramesBeforeIt(): void
    {
        // Frame 19 is the last whose header is in the first 8 KiB; the read after it fails.
        $handle = ScriptedStream::open('mp3-read', self::f128(40), ['readFailsFrom' => 8192]);

        self::assertEqualsWithDelta(20 * self::F128, Mp3Scan::seconds($handle), 1e-9);
        fclose($handle);
    }

    public function testASeekThatFailsEndsTheWalkWithTheFramesBeforeIt(): void
    {
        // Frame 19 runs past the first 8 KiB read, so stepping over it seeks, and the seek fails.
        $handle = ScriptedStream::open('mp3-seek', self::f128(40), ['seekFailsFrom' => 8192]);

        self::assertEqualsWithDelta(20 * self::F128, Mp3Scan::seconds($handle), 1e-9);
        fclose($handle);
    }
}
