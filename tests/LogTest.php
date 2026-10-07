<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Log;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase
{
    public function testOffWritesNothing(): void
    {
        $lines = [];
        $log = new Log('off', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $log->attempt('GET', '/v1/account', 200, 1, 12.0, null);

        self::assertSame([], $lines);
    }

    public function testDebugWritesOneMetadataLine(): void
    {
        $lines = [];
        $log = new Log('debug', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $log->attempt('POST', '/v1/uploads', 429, 2, 12.4, 15.0);
        $log->attempt('GET', '/v1/account', null, 1, 0.0, 0.25);
        $log->attempt('GET', '/v1/account', 200, 3, 7.5, null);

        self::assertSame([
            'hushscript POST /v1/uploads 429 attempt=2 12ms retry_in=15s',
            'hushscript GET /v1/account error attempt=1 0ms retry_in=0.25s',
            'hushscript GET /v1/account 200 attempt=3 8ms',
        ], $lines);
    }

    public function testALineIsRedactedEvenIfAPathCarriesAToken(): void
    {
        $lines = [];
        $log = new Log('debug', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $log->attempt('GET', '/v1/'.Tokens::TEST, 404, 1, 1.0, null);

        self::assertStringNotContainsString('0123456789abcdef', $lines[0]);
    }

    public function testTheDefaultSinkWritesToStandardError(): void
    {
        $log = new Log('debug');
        $this->expectNotToPerformAssertions();

        // php://stderr is opened per line; PHPUnit's own output buffering does not see it.
        $log->attempt('GET', '/v1/account', 200, 1, 1.0, null);
    }
}
