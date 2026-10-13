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
        // In a child process: the test runner's own standard error must stay clean (Infection stops on any output there).
        $script = 'require $argv[1]; (new Hushscript\\Log("debug"))->attempt("GET", "/v1/account", 200, 1, 1.0, null);';
        $process = proc_open(
            [PHP_BINARY, '-r', $script, dirname(__DIR__).'/vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process));
        self::assertSame('', $stdout);
        self::assertSame("hushscript GET /v1/account 200 attempt=1 1ms\n", $stderr);
    }
}
