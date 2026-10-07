<?php

declare(strict_types=1);

/*
 * Transcribes one recording and prints the text.
 *
 *     php examples/transcribe.php meeting.mp3
 */

use Hushscript\Client;
use Hushscript\Exception\HushscriptException;

require __DIR__.'/../vendor/autoload.php';

/** @param list<string> $arguments */
function transcribeExample(array $arguments): int
{
    if (count($arguments) !== 1) {
        fwrite(STDERR, "usage: transcribe.php <recording>\n");

        return 2;
    }
    $hs = new Client(); // reads HUSHSCRIPT_TOKEN
    $transcript = $hs->transcribe($arguments[0], [
        'on_progress' => static function (array $progress): void {
            $detail = $progress['percent'] ?? $progress['state'] ?? '';
            echo "\r", is_scalar($progress['stage']) ? $progress['stage'] : '', ' ', is_scalar($detail) ? $detail : '', '      ';
        },
    ]);
    echo "\n", $transcript->text(), "\n";

    return 0;
}

try {
    exit(transcribeExample(array_slice($argv, 1)));
} catch (HushscriptException $error) {
    fwrite(STDERR, "{$error->errorCode}: ".($error->hint ?? $error->getMessage())."\n");
    if ($error->resumeToken !== null) {
        fwrite(STDERR, "Pick it up later with \$hs->resume('{$error->resumeToken}', \$file).\n");
    }
    exit(1);
}
