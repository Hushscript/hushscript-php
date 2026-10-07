<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use Hushscript\Api;
use Hushscript\Credentials;
use Hushscript\Http\RetryMiddleware;
use Hushscript\Log;

/** A middleware and an Api wired to a fake client and a fake clock, with no jitter. */
final class Rig
{
    public const BASE = 'http://127.0.0.1:4010';

    public readonly Credentials $credentials;

    public readonly RetryMiddleware $transport;

    public readonly Api $api;

    /** @var list<string> */
    public array $logLines = [];

    public function __construct(
        public readonly FakeHttp $http,
        public readonly FakeClock $clock = new FakeClock,
        int $maxRetries = 3,
        string $token = Tokens::TEST,
        string $logLevel = 'debug',
    ) {
        $this->credentials = new Credentials($token);
        $this->transport = new RetryMiddleware(
            $http,
            $this->credentials,
            self::BASE,
            $clock,
            new Log($logLevel, function (string $line): void {
                $this->logLines[] = $line;
            }),
            $maxRetries,
            jitter: static fn (float $ceiling): float => $ceiling,
        );
        $this->api = new Api($this->transport, $this->credentials, self::BASE);
    }
}
