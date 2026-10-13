<?php

declare(strict_types=1);

/*
 * Transcribes one recording and writes it as subtitles.
 *
 *     php examples/export_srt.php meeting.mp3 meeting.srt
 */

use Hushscript\Client;
use Hushscript\Exception\HushscriptException;

require __DIR__.'/../vendor/autoload.php';

/** @param list<string> $arguments */
function exportSrtExample(array $arguments): int
{
    if (count($arguments) !== 2) {
        fwrite(STDERR, "usage: export_srt.php <recording> <subtitle file>\n");

        return 2;
    }
    $hs = new Client(); // reads HUSHSCRIPT_TOKEN
    $transcript = $hs->transcribe($arguments[0]);
    // Byte for byte what the Hushscript app exports; `toSrt(null, false)` keeps one cue per utterance.
    file_put_contents($arguments[1], $transcript->toSrt());
    echo "wrote {$arguments[1]}\n";

    return 0;
}

try {
    exit(exportSrtExample(array_slice($argv ?? [], 1)));
} catch (HushscriptException $error) {
    fwrite(STDERR, "{$error->errorCode}: ".($error->hint ?? $error->getMessage())."\n");
    exit(1);
}
