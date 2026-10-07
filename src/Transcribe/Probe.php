<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Exception\UnsupportedMediaError;

/**
 * The duration of a file in seconds (spec 8.2). The server bills the duration it probes at
 * `complete`, so the probe errs high: it is never under the file's true duration.
 *
 * getID3 reads the container; two of its answers are corrected. It leaves the Xing/Info frame out of
 * an MP3's length and reads a headerless VBR MP3 from the first frame's bitrate, so an MP3 is also
 * counted frame by frame and the larger value wins. It subtracts the pre-skip of an Opus stream,
 * which the declared duration must not do, so Opus is read from the last page's granule position
 * (granule / 48000).
 */
final class Probe
{
    private const OPUS_RATE = 48000;

    private const TAIL_BYTES = 65536;

    private const ID3_HEADER = 10;

    private const FRAME_HEADER = 4;

    private const RESYNC_LIMIT = 65536;

    private const MPEG1_BITRATES = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];

    private const MPEG2_BITRATES = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];

    private const SAMPLE_RATES = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];

    private function __construct()
    {
    }

    /**
     * @throws UnsupportedMediaError duration_unreadable
     */
    public static function seconds(Source $source): float
    {
        $format = Sniff::format($source->head());
        $found = [];
        $declared = self::container($source->path);
        if ($declared !== null) {
            $found[] = $declared;
        }
        if ($format === Sniff::MP3) {
            $scanned = self::scanMp3($source->path);
            if ($scanned !== null) {
                $found[] = $scanned;
            }
        }
        if ($format === Sniff::OGG) {
            $opus = self::opusSeconds($source->path);
            if ($opus !== null) {
                // The granule position replaces getID3's figure, which leaves the pre-skip out.
                $found = [$opus];
            }
        }
        $seconds = $found === [] ? null : max($found);
        if ($seconds === null || ! is_finite($seconds) || $seconds <= 0) {
            throw self::unreadable($found === [] ? 'nothing could be read' : 'the file reports no length');
        }

        return $seconds;
    }

    private static function unreadable(string $reason): UnsupportedMediaError
    {
        return new UnsupportedMediaError('Could not read the duration ('.$reason.'); pass duration_seconds.', 'duration_unreadable');
    }

    private static function container(string $path): ?float
    {
        $reader = new \getID3();
        $reader->option_tag_id3v1 = false;
        $reader->option_tag_id3v2 = false;
        $reader->option_tag_lyrics3 = false;
        $reader->option_tag_apetag = false;
        $reader->option_tags_process = false;
        $reader->option_tags_html = false;
        $reader->option_extra_info = false;
        $reader->option_save_attachments = false;
        $reader->option_md5_data = false;
        // getID3 predates current PHP and may raise notices or deprecations on odd files; none of them is the caller's.
        set_error_handler(static fn (): bool => true);
        try {
            $info = $reader->analyze($path);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
        $seconds = $info['playtime_seconds'] ?? null;
        if (isset($info['error']) || (! is_int($seconds) && ! is_float($seconds))) {
            return null;
        }

        return (float) $seconds;
    }

    /** The exact duration of an MP3 from its Layer III frames, or null when it has none. */
    private static function scanMp3(string $path): ?float
    {
        set_error_handler(static fn (): bool => true);
        $handle = fopen($path, 'rb');
        restore_error_handler();
        if ($handle === false) {
            return null;
        }
        try {
            $head = (string) fread($handle, self::ID3_HEADER);
            $start = 0;
            if (strlen($head) === self::ID3_HEADER && str_starts_with($head, 'ID3')) {
                $size = 0;
                for ($index = 6; $index < 10; $index++) {
                    $size = ($size << 7) | (ord($head[$index]) & 0x7F);
                }
                $start = self::ID3_HEADER + $size + ((ord($head[5]) & 0x10) !== 0 ? self::ID3_HEADER : 0);
            }
            fseek($handle, $start);
            $total = 0.0;
            $frames = 0;
            $skipped = 0;
            while (true) {
                $header = fread($handle, self::FRAME_HEADER);
                if ($header === false || strlen($header) < self::FRAME_HEADER) {
                    break;
                }
                $frame = self::frame($header);
                if ($frame === null) {
                    $skipped++;
                    if ($skipped > self::RESYNC_LIMIT && $frames === 0) {
                        break;
                    }
                    fseek($handle, -(self::FRAME_HEADER - 1), SEEK_CUR);

                    continue;
                }
                [$length, $samples, $rate] = $frame;
                $total += $samples / $rate;
                $frames++;
                fseek($handle, $length - self::FRAME_HEADER, SEEK_CUR);
            }

            return $frames === 0 ? null : $total;
        } finally {
            fclose($handle);
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

    /** granule position of the last Ogg page / 48000 for an Opus stream; null for anything else. */
    private static function opusSeconds(string $path): ?float
    {
        set_error_handler(static fn (): bool => true);
        $handle = fopen($path, 'rb');
        restore_error_handler();
        if ($handle === false) {
            return null;
        }
        try {
            $first = (string) fread($handle, 512);
            if (! str_contains($first, 'OpusHead')) {
                return null;
            }
            $size = fstat($handle)['size'] ?? 0;
            fseek($handle, max(0, $size - self::TAIL_BYTES));
            $tail = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        $position = strrpos($tail, 'OggS');
        while ($position !== false) {
            if (strlen($tail) >= $position + 14) {
                $granule = unpack('P', substr($tail, $position + 6, 8));
                $value = is_array($granule) ? ($granule[1] ?? null) : null;
                if (is_int($value) && $value >= 0) {
                    return $value / self::OPUS_RATE;
                }
            }
            $position = $position === 0 ? false : strrpos($tail, 'OggS', $position - strlen($tail) - 1);
        }

        return null;
    }
}
