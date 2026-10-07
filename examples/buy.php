<?php

declare(strict_types=1);

/*
 * Spends money: it needs a pack id and --yes before it charges the saved card.
 *
 *     php examples/buy.php 300min                  shows the packs and the notice, buys nothing
 *     php examples/buy.php 300min --yes            buys the pack
 *     php examples/buy.php 300min --yes --key KEY  asks again with the key of an earlier attempt
 */

use Hushscript\Client;
use Hushscript\Exception\HushscriptException;

require __DIR__.'/../vendor/autoload.php';

const NOTICE = 'Minutes are added to your account instantly after payment. All purchases are final and non-refundable.';

// The statuses of an answer that charged nothing: the API refused the purchase (4021 and 4023 are 403, a rate
// limit 429). Any other outcome after the request was sent, a 408, a 409, a 5xx, no status at all or an
// unusable answer, may have reached the card.
const REFUSALS = [400, 401, 402, 403, 404, 422, 429];

/** True when the purchase request went out and the answer was not one of the refusals. */
function mayHaveCharged(HushscriptException $error): bool
{
    return $error->idempotencyKey !== null && ! in_array($error->status, REFUSALS, true);
}

function report(HushscriptException $error): void
{
    fwrite(STDERR, "{$error->errorCode}: ".($error->hint ?? $error->getMessage())."\n");
    if (mayHaveCharged($error)) {
        fwrite(STDERR, "The card may have been charged. Run again with the same pack and --key {$error->idempotencyKey}: it cannot charge twice.\n");
    } else {
        fwrite(STDERR, "Nothing was charged.\n");
    }
}

/** @param list<string> $arguments */
function buyExample(array $arguments): int
{
    $pack = null;
    $yes = false;
    $key = null;
    for ($i = 0; $i < count($arguments); $i++) {
        match ($arguments[$i]) {
            '--yes' => $yes = true,
            '--key' => $key = $arguments[++$i] ?? null,
            default => $pack ??= $arguments[$i],
        };
    }
    if ($pack === null) {
        fwrite(STDERR, "usage: buy.php <pack> [--yes] [--key KEY]\n");

        return 2;
    }
    $hs = new Client(); // reads HUSHSCRIPT_TOKEN
    $chosen = null;
    foreach ($hs->billing()->packs() as $candidate) {
        echo "{$candidate->id}: {$candidate->minutes} minutes for {$candidate->price->formatted}\n";
        if ($candidate->id === $pack) {
            $chosen = $candidate;
        }
    }
    if ($chosen === null) {
        fwrite(STDERR, "Name one of the packs above to buy it.\n");

        return 2;
    }
    // Show the buyer this notice before the call; `acknowledgeNoRefund` records it was shown.
    echo NOTICE, "\n";
    if (! $yes) {
        echo "Nothing was bought. Run again with --yes to charge your saved card {$chosen->price->formatted} for {$chosen->id}.\n";

        return 1;
    }
    $purchase = $hs->buy($chosen->id, true, $key);
    echo "bought {$chosen->id}; now {$purchase->minutes} minutes\n";

    return 0;
}

try {
    exit(buyExample(array_slice($argv, 1)));
} catch (HushscriptException $error) {
    report($error);
    exit(1);
}
