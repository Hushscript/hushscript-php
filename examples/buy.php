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

// The statuses of an answer that charged nothing: refusals the server decides from the request alone (400, a 403
// with numeric code 4021 or 4023, 404, 422) and a 402, which comes after the server's replay of a completed purchase. A 401
// or a 429 is not on the list: both run before that replay, so after the SDK's one retry they can follow a first
// attempt that charged the card. Any other outcome after the request was sent may have reached the card too.
const REFUSALS = [400, 402, 404, 422];

/** True when a failed buy carries a key and the answer does not prove a refusal. */
function mayHaveCharged(HushscriptException $error): bool
{
    $refused = in_array($error->status, REFUSALS, true)
        || ($error->status === 403 && in_array($error->numericCode, [4021, 4023], true));

    return $error->idempotencyKey !== null && ! $refused;
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

if (! defined('HUSHSCRIPT_EXAMPLE_NO_MAIN')) {
    try {
        exit(buyExample(array_slice($argv ?? [], 1)));
    } catch (HushscriptException $error) {
        report($error);
        exit(1);
    }
}
