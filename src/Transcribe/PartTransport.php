<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * How part requests leave the machine. The upload loop owns concurrency, retries and time; the
 * transport only starts a request and lets transfers make progress. A request that fails in transit
 * rejects with a PartFailure, which carries no URL, header or body: Guzzle's own exceptions hold the
 * request, and a presigned URL is a credential.
 */
interface PartTransport
{
    /**
     * Starts the request without waiting for it. The promise fulfils with the response whatever its
     * status, or rejects with a PartFailure.
     */
    public function send(#[\SensitiveParameter] RequestInterface $request, float $timeout): PromiseInterface;

    /** Lets in-flight transfers progress; waits a short moment for them when none has finished. */
    public function tick(): void;
}
