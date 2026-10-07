<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * One part on its way: the bytes, which way it is going, how many times it has been tried and what
 * its request in flight answered. The upload loop moves it; nothing here talks to the network.
 *
 * @internal
 */
final class PartRun
{
    public ?PromiseInterface $promise = null;

    /** The answer of the request in flight: a response, a failure, or null while it is still out. */
    public ResponseInterface|PartFailure|null $answer = null;

    public bool $proxied = false;

    public ?string $url = null;

    /** Retries of the current way and URL, counted for the retry table. */
    public int $attempt = 0;

    /** R2 403s answered with a fresh URL, and the rounds of retries after a success elsewhere (spec 8.4). */
    public int $refreshes = 0;

    public int $rounds = 0;

    public ?float $notBefore = null;

    public float $startedAt = 0.0;

    public bool $done = false;

    public function __construct(
        public readonly int $n,
        #[\SensitiveParameter] public readonly string $data,
    ) {
    }

    public function length(): int
    {
        return strlen($this->data);
    }
}
