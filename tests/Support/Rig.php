<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use Hushscript\Api;
use Hushscript\Credentials;
use Hushscript\Http\Exchange;
use Hushscript\Http\RetryMiddleware;
use Hushscript\Log;
use Psr\Http\Client\ClientInterface;

/** A middleware and an Api wired to a fake client and a fake clock, with no jitter. */
final class Rig
{
    public const BASE = 'http://127.0.0.1:4010';

    public readonly Credentials $credentials;

    public readonly RetryMiddleware $transport;

    public readonly Api $api;

    public readonly Exchange $exchange;

    /** @var list<string> */
    public array $logLines = [];

    public function __construct(
        public readonly FakeHttp $http,
        public readonly FakeClock $clock = new FakeClock(),
        int $maxRetries = 3,
        string $token = Tokens::TEST,
        string $logLevel = 'debug',
        ?ClientInterface $inner = null,
    ) {
        $this->credentials = new Credentials($token);
        $this->transport = new RetryMiddleware(
            $inner ?? $http,
            $this->credentials,
            self::BASE,
            $clock,
            new Log($logLevel, function (string $line): void {
                $this->logLines[] = $line;
            }),
            $maxRetries,
            jitter: static fn (float $ceiling): float => $ceiling,
        );
        $this->exchange = new Exchange($this->transport, $this->credentials, self::BASE);
        $this->api = new Api($this->exchange);
    }
}
