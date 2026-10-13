<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Http\Outcome;
use Hushscript\Http\RetryClass;
use Hushscript\Http\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    private static function jitter(): \Closure
    {
        return static fn (float $ceiling): float => $ceiling;
    }

    /** @return iterable<string, array{int, float}> */
    public static function ceilings(): iterable
    {
        yield '0' => [0, 0.5];
        yield '1' => [1, 1.0];
        yield '2' => [2, 2.0];
        yield '3' => [3, 4.0];
        yield '4' => [4, 8.0];
        yield '5' => [5, 8.0];
        yield '40' => [40, 8.0];
        yield 'largest int' => [PHP_INT_MAX, 8.0];
    }

    #[DataProvider('ceilings')]
    public function testBackoffCeiling(int $attempt, float $expected): void
    {
        self::assertSame($expected, RetryPolicy::backoffCeiling($attempt));
    }

    public function testFullJitterIsTheCallersClosureOverTheCeiling(): void
    {
        $decision = RetryPolicy::decide(RetryClass::Safe, 2, 3, new Outcome(503), static fn (float $ceiling): float => $ceiling / 4);

        self::assertTrue($decision->retry);
        self::assertSame(0.5, $decision->delay);
    }

    public function testFullJitterMapsTheLowestDrawToZeroAndTheHighestToTheCeiling(): void
    {
        $lowest = static fn (int $min, int $max): int => $min;
        $highest = static fn (int $min, int $max): int => $max;
        $middle = static fn (int $min, int $max): int => intdiv($min + $max, 2);

        self::assertSame(0.0, RetryPolicy::fullJitter(8.0, $lowest));
        self::assertSame(8.0, RetryPolicy::fullJitter(8.0, $highest));
        self::assertSame(4.0, RetryPolicy::fullJitter(8.0, $middle));
        self::assertSame(0.5, RetryPolicy::fullJitter(0.5, $highest));
    }

    public function testFullJitterSamplesTheWholeRange(): void
    {
        foreach ([0.5, 8.0] as $ceiling) {
            $samples = [];
            for ($index = 0; $index < 128; $index++) {
                $samples[] = RetryPolicy::fullJitter($ceiling);
            }
            self::assertNotEmpty($samples);
            self::assertGreaterThanOrEqual(0.0, min($samples));
            self::assertLessThanOrEqual($ceiling, max($samples));
            self::assertLessThan($ceiling / 4, min($samples));
            self::assertGreaterThan($ceiling * 3 / 4, max($samples));
            self::assertGreaterThan(1, count(array_unique($samples)));
        }
    }

    public function testASuccessIsNeverRetried(): void
    {
        self::assertFalse(RetryPolicy::decide(RetryClass::Safe, 0, 3, new Outcome(200), self::jitter())->retry);
        self::assertFalse(RetryPolicy::decide(RetryClass::Safe, 0, 3, new Outcome(), self::jitter())->retry);
    }

    public function testCreateLimitKinds(): void
    {
        self::assertNull(RetryPolicy::createLimitKind(RetryClass::Safe, new Outcome(429)));
        self::assertNull(RetryPolicy::createLimitKind(RetryClass::Create, new Outcome(500)));
        self::assertSame('rate_limited', RetryPolicy::createLimitKind(RetryClass::Create, new Outcome(429)));
        self::assertSame('rate_limited', RetryPolicy::createLimitKind(RetryClass::Create, new Outcome(429, slug: 'x')));
        self::assertSame('active_jobs', RetryPolicy::createLimitKind(RetryClass::Create, new Outcome(429, slug: 'too_many_active_jobs')));
    }

    public function testTheOwn429CounterDefaultsToTheAttempt(): void
    {
        $outcome = new Outcome(429, slug: 'rate_limited');

        self::assertSame(15.0, RetryPolicy::decide(RetryClass::Create, 0, 9, $outcome, self::jitter())->delay);
        self::assertSame(30.0, RetryPolicy::decide(RetryClass::Create, 1, 9, $outcome, self::jitter())->delay);
        self::assertSame(60.0, RetryPolicy::decide(RetryClass::Create, 2, 9, $outcome, self::jitter())->delay);
        self::assertFalse(RetryPolicy::decide(RetryClass::Create, 3, 9, $outcome, self::jitter())->retry);
    }

    public function testActiveJobsTriesAreCountedOnTheirOwn(): void
    {
        $outcome = new Outcome(429, slug: 'too_many_active_jobs');

        self::assertTrue(RetryPolicy::decide(RetryClass::Create, 8, 3, $outcome, self::jitter(), 8)->retry);
        self::assertFalse(RetryPolicy::decide(RetryClass::Create, 9, 3, $outcome, self::jitter(), 9)->retry);
        self::assertFalse(RetryPolicy::decide(RetryClass::Create, 0, 0, $outcome, self::jitter(), 0)->retry);
    }

    public function testExplicitRateLimitCounterIsIndependentOfOtherRetries(): void
    {
        $outcome = new Outcome(429, slug: 'rate_limited');

        foreach ([15.0, 30.0, 60.0] as $own => $delay) {
            $decision = RetryPolicy::decide(RetryClass::Create, 7, 9, $outcome, self::jitter(), $own);
            self::assertTrue($decision->retry);
            self::assertSame($delay, $decision->delay);
        }
        self::assertFalse(RetryPolicy::decide(RetryClass::Create, 0, 9, $outcome, self::jitter(), 3)->retry);
        $active = RetryPolicy::decide(RetryClass::Create, 20, 3, new Outcome(429, slug: 'too_many_active_jobs'), self::jitter(), 8);
        self::assertTrue($active->retry);
        self::assertSame(30.0, $active->delay);
    }

    public function testRetryAfterAtTheCeilingIsWaitedAboveItIsNot(): void
    {
        self::assertSame(60.0, RetryPolicy::decide(RetryClass::Safe, 0, 3, new Outcome(429, 60), self::jitter())->delay);
        self::assertFalse(RetryPolicy::decide(RetryClass::Safe, 0, 3, new Outcome(429, 61), self::jitter())->retry);
        self::assertSame(0.0, RetryPolicy::decide(RetryClass::Safe, 0, 3, new Outcome(429, 0), self::jitter())->delay);
    }

    public function testRetryAfterOnANonRetryableStatusDoesNotMakeItRetryable(): void
    {
        self::assertFalse(RetryPolicy::decide(RetryClass::Safe, 0, 3, new Outcome(400, 1), self::jitter())->retry);
    }

    /** @return iterable<string, array{RetryClass, int, bool}> */
    public static function statuses(): iterable
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            yield "safe {$status}" => [RetryClass::Safe, $status, true];
        }
        yield 'safe 501' => [RetryClass::Safe, 501, false];
        yield 'safe 505' => [RetryClass::Safe, 505, false];
        yield 'safe 404' => [RetryClass::Safe, 404, false];
        foreach ([RetryClass::Part, RetryClass::Complete, RetryClass::Create] as $class) {
            foreach ([429, 500, 501, 502, 599] as $status) {
                yield "{$class->name} {$status}" => [$class, $status, true];
            }
            yield "{$class->name} 403" => [$class, 403, false];
            yield "{$class->name} 600" => [$class, 600, false];
            yield "{$class->name} 499" => [$class, 499, false];
        }
        foreach ([502, 503] as $status) {
            yield "purchase {$status}" => [RetryClass::Purchase, $status, true];
        }
        foreach ([429, 500, 504, 403] as $status) {
            yield "purchase {$status}" => [RetryClass::Purchase, $status, false];
        }
        foreach ([429, 500, 502, 503] as $status) {
            yield "never {$status}" => [RetryClass::Never, $status, false];
            yield "mutate {$status}" => [RetryClass::Mutate, $status, false];
        }
    }

    #[DataProvider('statuses')]
    public function testRetryableStatuses(RetryClass $class, int $status, bool $retry): void
    {
        self::assertSame($retry, RetryPolicy::decide($class, 0, 3, new Outcome($status), self::jitter())->retry);
    }

    public function testNetworkFailuresPerClass(): void
    {
        $sent = new Outcome(failed: true);
        $notSent = new Outcome(failed: true, notSent: true);

        foreach ([RetryClass::Safe, RetryClass::Part, RetryClass::Complete, RetryClass::Create, RetryClass::Purchase] as $class) {
            self::assertTrue(RetryPolicy::decide($class, 0, 3, $sent, self::jitter())->retry, $class->name);
            self::assertTrue(RetryPolicy::decide($class, 0, 3, $notSent, self::jitter())->retry, $class->name);
        }
        self::assertFalse(RetryPolicy::decide(RetryClass::Mutate, 0, 3, $sent, self::jitter())->retry);
        self::assertTrue(RetryPolicy::decide(RetryClass::Mutate, 0, 3, $notSent, self::jitter())->retry);
        self::assertFalse(RetryPolicy::decide(RetryClass::Never, 0, 3, $sent, self::jitter())->retry);
        self::assertFalse(RetryPolicy::decide(RetryClass::Never, 0, 3, $notSent, self::jitter())->retry);
    }

    public function testTheBudgetIsMaxRetriesAndPurchaseIsCappedAtOne(): void
    {
        $outcome = new Outcome(503);

        self::assertTrue(RetryPolicy::decide(RetryClass::Safe, 2, 3, $outcome, self::jitter())->retry);
        self::assertFalse(RetryPolicy::decide(RetryClass::Safe, 3, 3, $outcome, self::jitter())->retry);
        self::assertFalse(RetryPolicy::decide(RetryClass::Safe, 0, 0, $outcome, self::jitter())->retry);
        self::assertTrue(RetryPolicy::decide(RetryClass::Purchase, 0, 9, $outcome, self::jitter())->retry);
        self::assertFalse(RetryPolicy::decide(RetryClass::Purchase, 1, 9, $outcome, self::jitter())->retry);
        self::assertFalse(RetryPolicy::decide(RetryClass::Purchase, 0, 0, $outcome, self::jitter())->retry);
    }
}
