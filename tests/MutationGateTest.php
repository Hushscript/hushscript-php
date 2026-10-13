<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MutationGateTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function reports(): iterable
    {
        yield 'exact floor' => [['killedCount' => 80, 'totalMutantsCount' => 100], 0];
        yield 'errors cannot rescue MSI' => [['killedCount' => 79, 'errorCount' => 21, 'totalMutantsCount' => 100, 'msi' => 100], 1];
        yield 'timeouts cannot rescue MSI' => [['killedCount' => 79, 'timeOutCount' => 21, 'totalMutantsCount' => 100, 'msi' => 100], 1];
        yield 'escaped timeouts cannot rescue MSI' => [['killedCount' => 79, 'escapedCount' => 0, 'timeOutEscapedCount' => 21, 'totalMutantsCount' => 100, 'msi' => 100], 1];
        yield 'skips cannot rescue MSI' => [['killedCount' => 79, 'skippedCount' => 21, 'totalMutantsCount' => 100, 'msi' => 100], 1];
        yield 'syntax errors cannot rescue MSI' => [['killedCount' => 79, 'syntaxErrorCount' => 21, 'totalMutantsCount' => 100, 'msi' => 100], 1];
        yield 'negative kills' => [['killedCount' => -1, 'totalMutantsCount' => 100], 1];
        yield 'string kills' => [['killedCount' => '80', 'totalMutantsCount' => 100], 1];
        yield 'rounding cannot rescue score' => [['killedCount' => 79999, 'totalMutantsCount' => 100000], 1];
        yield 'no mutants' => [['killedCount' => 0, 'totalMutantsCount' => 0], 1];
        yield 'impossible kills' => [['killedCount' => 101, 'totalMutantsCount' => 100], 1];
        yield 'missing counts' => [[], 1];
    }

    /** @param array<string, mixed> $stats */
    #[DataProvider('reports')]
    public function testOnlyTestKillsMeetTheFloor(array $stats, int $expected): void
    {
        self::assertSame($expected, self::gate(['stats' => $stats]));
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function mutantRuns(): iterable
    {
        $ran = "PHPUnit 11.5.57 by Sebastian Bergmann and contributors.\n\nOK (3 tests, 7 assertions)";
        $none = "PHPUnit 11.5.57 by Sebastian Bergmann and contributors.\n\nNo tests executed!";
        yield 'escaped after running its tests' => [['escaped' => [['processOutput' => $ran]]], 0];
        yield 'escaped without running a test' => [['escaped' => [['processOutput' => $ran], ['processOutput' => $none]]], 1];
        yield 'timed out without running a test' => [['timeouted' => [['processOutput' => $none]]], 1];
        yield 'killed without running a test' => [['killed' => [['processOutput' => $none]]], 1];
    }

    /** @param array<string, mixed> $mutants */
    #[DataProvider('mutantRuns')]
    public function testAMutantRunWithoutTestsFailsTheGate(array $mutants, int $expected): void
    {
        self::assertSame($expected, self::gate(['stats' => ['killedCount' => 100, 'totalMutantsCount' => 100], ...$mutants]));
    }

    /** @param array<string, mixed> $report */
    private static function gate(array $report): int
    {
        $path = tempnam(sys_get_temp_dir(), 'mutation-report-');
        self::assertIsString($path);
        try {
            file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR));
            $process = proc_open(
                [PHP_BINARY, __DIR__.'/../scripts/check-mutation.php', $path, '80'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            foreach ($pipes as $pipe) {
                stream_get_contents($pipe);
                fclose($pipe);
            }

            return proc_close($process);
        } finally {
            unlink($path);
        }
    }
}
