<?php

declare(strict_types=1);

/*
 * Prints the balance, the latest transcripts and the jobs still running.
 *
 *     php examples/balance.php
 */

use Hushscript\Client;
use Hushscript\Exception\HushscriptException;

require __DIR__.'/../vendor/autoload.php';

function balanceExample(): int
{
    $hs = new Client(); // reads HUSHSCRIPT_TOKEN
    $balance = $hs->balance();
    // Held minutes are already taken out of `minutes`; do not subtract them again.
    echo "{$balance->minutes} minutes to spend, {$balance->heldSeconds} s held by running jobs\n";
    $shown = 0;
    foreach ($hs->transcripts()->list() as $summary) {
        echo $summary->id, ' ', $summary->title, "\n";
        if (++$shown === 5) {
            break;
        }
    }
    foreach ($hs->jobs()->list(['active' => true]) as $job) {
        echo 'running ', $job->id, ' ', $job->state, "\n";
    }

    return 0;
}

try {
    exit(balanceExample());
} catch (HushscriptException $error) {
    fwrite(STDERR, "{$error->errorCode}: {$error->getMessage()}\n");
    exit(1);
}
