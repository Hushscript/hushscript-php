<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Exception\HushscriptException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

define('HUSHSCRIPT_EXAMPLE_NO_MAIN', true);
require_once __DIR__.'/../examples/buy.php';

final class BuyExampleTest extends TestCase
{
    /** @return iterable<string, array{?int, ?int, ?string, bool}> */
    public static function purchaseFailures(): iterable
    {
        foreach ([400, 402, 404, 422] as $status) {
            yield "refusal $status" => [$status, null, 'same-key', false];
        }
        yield '403 4021 refusal' => [403, 4021, 'same-key', false];
        yield '403 4023 refusal' => [403, 4023, 'same-key', false];
        yield 'bare 403' => [403, null, 'same-key', true];
        yield 'account suspended' => [403, 2013, 'same-key', true];
        yield 'refusal code with a 401' => [401, 4021, 'same-key', true];
        yield 'refusal code with a 503' => [503, 4023, 'same-key', true];
        foreach ([401, 408, 409, 429, 500, 502, 503, 200] as $status) {
            yield "uncertain $status" => [$status, null, 'same-key', true];
        }
        yield 'network after send' => [null, null, 'same-key', true];
        yield 'before send' => [null, null, null, false];
    }

    #[DataProvider('purchaseFailures')]
    public function testOnlyAProvenRefusalSuppressesTheSameKeyWarning(?int $status, ?int $numericCode, ?string $key, bool $expected): void
    {
        $error = new HushscriptException('failed', numericCode: $numericCode, status: $status);
        $error->idempotencyKey = $key;

        self::assertSame($expected, \mayHaveCharged($error));
    }

    /** @return iterable<string, array{?int, string}> */
    public static function reportedFailures(): iterable
    {
        yield '403 4021 refusal' => [4021, "failed: failed\nNothing was charged.\n"];
        yield 'bare 403' => [null, "failed: failed\nThe card may have been charged. Run again with the same pack and --key same-key: it cannot charge twice.\n"];
    }

    #[DataProvider('reportedFailures')]
    public function testReportWritesTheExactPurchaseOutcome(?int $numericCode, string $expected): void
    {
        $script = <<<'PHP'
define('HUSHSCRIPT_EXAMPLE_NO_MAIN', true);
require $argv[1];
$error = new Hushscript\Exception\HushscriptException('failed', 'failed', numericCode: $argv[2] === '' ? null : (int) $argv[2], status: 403);
$error->idempotencyKey = 'same-key';
report($error);
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, __DIR__.'/../examples/buy.php', $numericCode === null ? '' : (string) $numericCode],
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
        self::assertSame($expected, $stderr);
    }
}
