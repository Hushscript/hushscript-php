<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

/**
 * The exact duration of an MP3 from its Layer III frames. The file is read in 1 MiB blocks and walked
 * by offset; the bytes between frames are never walked one by one in PHP: an ID3v2 tag is skipped by
 * its declared size (only when what follows it is a frame or the end of the file), and anything else
 * is searched for the next valid frame header by one regular expression over the block. There is no
 * give-up point, because a cut-off would count less than the file holds and the probe is never
 * under the true duration.
 */
final class Mp3Scan
{
    private const BLOCK = 1 << 20;

    private const ID3_HEADER = 10;

    private const FRAME_HEADER = 4;

    private const MPEG1_BITRATES = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];

    private const MPEG2_BITRATES = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];

    private const SAMPLE_RATES = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];

    /** Sync, a version other than the reserved one, Layer III, a bitrate index of 1 to 14 and a sample rate index below 3. */
    private const HEADER_PATTERN = '/\xFF[\xE2\xE3\xF2\xF3\xFA\xFB][\x10-\x1B\x20-\x2B\x30-\x3B\x40-\x4B\x50-\x5B\x60-\x6B\x70-\x7B\x80-\x8B\x90-\x9B\xA0-\xAB\xB0-\xBB\xC0-\xCB\xD0-\xDB\xE0-\xEB]/sD';

    private string $buffer = '';

    private int $position = 0;

    /** The file offset of the first byte of the buffer. */
    private int $base = 0;

    private bool $ended = false;

    /**
     * @param  resource  $handle  an open, seekable file
     */
    private function __construct(private $handle, private readonly int $size)
    {
    }

    /**
     * @param  resource  $handle
     */
    public static function seconds($handle): ?float
    {
        $stat = fstat($handle);

        return (new self($handle, is_array($stat) ? $stat['size'] : 0))->walk();
    }

    private function walk(): ?float
    {
        $total = 0.0;
        $frames = 0;
        $this->skipTag();
        while ($this->have(self::FRAME_HEADER)) {
            $frame = self::frame(substr($this->buffer, $this->position, self::FRAME_HEADER));
            if ($frame !== null) {
                [$length, $samples, $rate] = $frame;
                $total += $samples / $rate;
                $frames++;
                $this->skip($length);

                continue;
            }
            if ($this->skipTag()) {
                continue;
            }
            if (! $this->resync()) {
                break;
            }
        }

        return $frames === 0 ? null : $total;
    }

    /** True when the buffer holds `$count` bytes from the position, reading more of the file when it does not. */
    private function have(int $count): bool
    {
        while (strlen($this->buffer) - $this->position < $count && ! $this->ended) {
            $more = fread($this->handle, self::BLOCK);
            if ($more === false || $more === '') {
                $this->ended = true;

                break;
            }
            $this->base += $this->position;
            $this->buffer = substr($this->buffer, $this->position).$more;
            $this->position = 0;
        }

        return strlen($this->buffer) - $this->position >= $count;
    }

    private function offset(): int
    {
        return $this->base + $this->position;
    }

    private function seek(int $offset): void
    {
        $this->buffer = '';
        $this->position = 0;
        $this->base = $offset;
        $this->ended = false;
        if (fseek($this->handle, $offset) !== 0) {
            $this->ended = true;
        }
    }

    private function skip(int $count): void
    {
        $left = strlen($this->buffer) - $this->position;
        if ($count <= $left) {
            $this->position += $count;

            return;
        }
        $this->seek($this->offset() + $count);
    }

    /**
     * Skips an ID3v2 tag that starts here: a strict header, then by the declared size, and only when
     * a frame follows, or the file ends there (so a stray "ID3" in audio data, or one that claims more bytes
     * than the file has, skips nothing).
     */
    private function skipTag(): bool
    {
        if (! $this->have(self::ID3_HEADER)) {
            return false;
        }
        $head = substr($this->buffer, $this->position, self::ID3_HEADER);
        if (! str_starts_with($head, 'ID3') || ord($head[3]) === 0xFF || ord($head[4]) === 0xFF) {
            return false;
        }
        $size = 0;
        for ($index = 6; $index < 10; $index++) {
            $byte = ord($head[$index]);
            if ($byte >= 0x80) {
                return false;
            }
            $size = ($size << 7) | $byte;
        }
        $from = $this->offset();
        $length = self::ID3_HEADER + $size + ((ord($head[5]) & 0x10) !== 0 ? self::ID3_HEADER : 0);
        if ($from + $length > $this->size) {
            return false;
        }
        $this->skip($length);
        if ($this->have(self::FRAME_HEADER) && self::frame(substr($this->buffer, $this->position, self::FRAME_HEADER)) === null) {
            $this->seek($from);

            return false;
        }

        return true;
    }

    /** Moves to the next valid frame header after the position; false when the file has none. */
    private function resync(): bool
    {
        // After a failed skipTag() the buffer is empty at the tag: load it first, or the increment lands beyond it.
        if (! $this->have(1)) {
            return false;
        }
        ++$this->position;
        while (true) {
            if ($this->position <= strlen($this->buffer) && preg_match(self::HEADER_PATTERN, $this->buffer, $found, PREG_OFFSET_CAPTURE, $this->position) === 1) {
                $this->position = $found[0][1];

                return true;
            }
            if ($this->ended) {
                return false;
            }
            // A header can straddle two blocks: keep its first three bytes.
            $this->position = max($this->position, strlen($this->buffer) - (self::FRAME_HEADER - 1));
            if (! $this->have(strlen($this->buffer) - $this->position + 1)) {
                return false;
            }
        }
    }

    /**
     * @return array{int, int, int}|null  frame length, samples and sample rate of a Layer III frame header
     */
    private static function frame(string $header): ?array
    {
        $second = ord($header[1]);
        if ($header[0] !== "\xFF" || ($second & 0xE0) !== 0xE0) {
            return null;
        }
        $version = ($second >> 3) & 3;
        $layer = ($second >> 1) & 3;
        $third = ord($header[2]);
        $bitrateIndex = $third >> 4;
        $rateIndex = ($third >> 2) & 3;
        if ($version === 1 || $layer !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
            return null;
        }
        $table = $version === 3 ? self::MPEG1_BITRATES : self::MPEG2_BITRATES;
        $rate = self::SAMPLE_RATES[$version][$rateIndex];
        $samples = $version === 3 ? 1152 : 576;
        $length = intdiv(intdiv($samples, 8) * $table[$bitrateIndex] * 1000, $rate) + (($third >> 1) & 1);

        return [$length, $samples, $rate];
    }
}
