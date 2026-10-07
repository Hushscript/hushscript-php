<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

/**
 * The container of a file by its magic bytes, never by its name (spec 8.1). Matches
 * `backend/src/audio_probe.rs`: MP3 (an ID3 tag or a frame sync), ISO-BMFF (`ftyp` at offset 4: m4a,
 * mp4, mov), WAV, FLAC and Ogg. Anything else is null.
 */
final class Sniff
{
    public const HEAD_BYTES = 4096;

    public const MP3 = 'mp3';

    public const ISOBMFF = 'isobmff';

    public const WAV = 'wav';

    public const FLAC = 'flac';

    public const OGG = 'ogg';

    private function __construct()
    {
    }

    public static function format(string $head): ?string
    {
        if (str_starts_with($head, 'ID3') || (strlen($head) >= 2 && $head[0] === "\xFF" && (ord($head[1]) & 0xE0) === 0xE0)) {
            return self::MP3;
        }
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            return self::ISOBMFF;
        }
        if (strlen($head) >= 12 && in_array(substr($head, 0, 4), ['RIFF', 'RF64', 'BW64'], true) && substr($head, 8, 4) === 'WAVE') {
            return self::WAV;
        }
        if (str_starts_with($head, 'fLaC')) {
            return self::FLAC;
        }
        if (str_starts_with($head, 'OggS')) {
            return self::OGG;
        }

        return null;
    }
}
