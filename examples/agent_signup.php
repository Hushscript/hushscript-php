<?php

declare(strict_types=1);

/*
 * Signs an agent account up, waits for the checkout to be paid, claims the token and rotates it.
 *
 * The server shows each token once, so every one is written to the token file (mode 0600) the moment it
 * arrives, before anything else can fail.
 *
 *     php examples/agent_signup.php --terms 2026-10-01 --token-file agent-token
 */

use Hushscript\Client;
use Hushscript\Exception\ConflictError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\OutcomeUnknownError;

require __DIR__.'/../vendor/autoload.php';

const POLL_SECONDS = 5;

/** The shape the client checks of any token: the `hsr` prefix and printable ASCII only. */
function looksLikeToken(?string $text): bool
{
    return $text !== null && str_starts_with($text, 'hsr') && preg_match('/^[\x21-\x7E]+$/D', $text) === 1;
}

/**
 * Writes `$token` to `$path` with mode 0600. It goes to a private temporary file in the same directory
 * first and replaces `$path` by a rename, so the file never holds less than a whole token and an earlier
 * token is never truncated before the new one is safely on disk.
 */
function keep(string $path, string $token): void
{
    $temporary = tempnam(dirname($path), '.token-');
    if ($temporary === false) {
        throw new RuntimeException("cannot write next to {$path}");
    }
    try {
        chmod($temporary, 0o600);
        $file = fopen($temporary, 'wb');
        if ($file === false || fwrite($file, $token."\n") === false || ! fflush($file) || ! fsync($file) || ! fclose($file)) {
            throw new RuntimeException("cannot write {$temporary}");
        }
        if (! rename($temporary, $path)) {
            throw new RuntimeException("cannot replace {$path}");
        }
    } finally {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
    }
}

function run(string $terms, string $tokenFile): void
{
    $signup = Client::agentSignup([
        'email' => 'agent@example.com',
        'pack' => '45min',
        'accept_terms' => $terms,
        'agent' => ['name' => 'Example agent', 'platform' => 'custom'],
    ]);
    echo "pay {$signup->price->formatted} at {$signup->checkoutUrl}\n";

    // The claim answers wrong_state until the checkout is paid. A lost answer is never retried.
    while (true) {
        try {
            $account = Client::agentClaim($signup->signupId, $signup->claimSecret);
            break;
        } catch (ConflictError $error) {
            if ($error->errorCode !== 'wrong_state') {
                throw $error;
            }
            sleep(POLL_SECONDS);
        }
    }
    keep($tokenFile, $account->token); // before anything else can fail
    echo "claimed {$account->userId}; token saved to {$tokenFile}\n";

    $hs = new Client($account->token);
    echo "{$hs->balance()->minutes} minutes\n";
    $rotated = $hs->agent()->rotateToken(); // revokes the claimed token; the client sends the new one
    keep($tokenFile, $rotated->token);
    echo 'rotated; scopes: '.implode(', ', $rotated->scopes)."; new token saved to {$tokenFile}\n";
}

/** @param list<string> $arguments */
function agentSignupExample(array $arguments): int
{
    $options = getopt('', ['terms:', 'token-file:'], $rest);
    $terms = $options['terms'] ?? null;
    $tokenFile = $options['token-file'] ?? null;
    if (! is_string($terms) || ! is_string($tokenFile)) {
        fwrite(STDERR, "usage: agent_signup.php --terms <policy version> --token-file <file>\n");

        return 2;
    }
    try {
        run($terms, $tokenFile);
    } catch (HushscriptException $error) {
        fwrite(STDERR, "{$error->errorCode}: ".($error->hint ?? $error->getMessage())."\n");
        $received = $error instanceof OutcomeUnknownError ? $error->receivedToken() : null;
        if (looksLikeToken($received)) {
            keep($tokenFile, (string) $received);
            fwrite(STDERR, "A token that came with the failed answer is in {$tokenFile}.\n");
        } elseif (is_file($tokenFile)) {
            fwrite(STDERR, "Any token that was issued is in {$tokenFile}.\n");
        }

        return 1;
    }

    return 0;
}

exit(agentSignupExample(array_slice($argv, 1)));
