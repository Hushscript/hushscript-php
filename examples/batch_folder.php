<?php

declare(strict_types=1);

/*
 * Transcribes every recording in a folder, one after the other, and writes a text file for each.
 *
 *     php examples/batch_folder.php recordings transcripts
 */

use Hushscript\Client;
use Hushscript\Exception\HushscriptException;

require __DIR__.'/../vendor/autoload.php';

const AUDIO = ['mp3', 'm4a', 'mp4', 'mov', 'wav', 'flac', 'ogg', 'opus'];

function batchFolderExample(string $folder, string $out): int
{
    if (! is_dir($out) && ! mkdir($out, 0o755, true) && ! is_dir($out)) {
        fwrite(STDERR, "cannot create {$out}\n");

        return 1;
    }
    $recordings = glob(rtrim($folder, '/').'/*');
    if ($recordings === false) {
        $recordings = [];
    }
    sort($recordings);
    $failed = false;
    $hs = new Client(); // reads HUSHSCRIPT_TOKEN
    foreach ($recordings as $recording) {
        if (! is_file($recording) || ! in_array(strtolower(pathinfo($recording, PATHINFO_EXTENSION)), AUDIO, true)) {
            continue;
        }
        $name = basename($recording);
        try {
            // Creates are paced for you (4 a minute), so a long folder just takes a while.
            $transcript = $hs->transcribe($recording);
        } catch (HushscriptException $error) {
            $failed = true;
            echo rtrim("failed {$name}: {$error->errorCode} ".($error->hint ?? '')), "\n";

            continue;
        }
        file_put_contents(rtrim($out, '/').'/'.pathinfo($recording, PATHINFO_FILENAME).'.txt', $transcript->text());
        echo "done {$name}\n";
    }

    return $failed ? 1 : 0;
}

$arguments = array_slice($argv, 1);
if ($arguments === []) {
    $arguments = ['recordings', 'transcripts'];
}
if (count($arguments) !== 2) {
    fwrite(STDERR, "usage: batch_folder.php <folder> <output folder>\n");
    exit(2);
}
exit(batchFolderExample($arguments[0], $arguments[1]));
