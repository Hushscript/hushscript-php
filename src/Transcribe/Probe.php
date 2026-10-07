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
            return Mp3Scan::seconds($handle);
        } finally {
            fclose($handle);
        }
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
