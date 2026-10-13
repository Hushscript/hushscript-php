<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Http\WaitTicker;
use Hushscript\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class WaitTickerTest extends TestCase
{
    public function testADetachedTickerSleepsPlainly(): void
    {
        $clock = new FakeClock();
        $ticker = new WaitTicker();
        $ticks = 0;
        $ticker->attach(static fn (): float => INF, static function () use (&$ticks): void {
            $ticks++;
        });
        $ticker->detach();

        $ticker->sleep($clock, 3.0);

        self::assertSame(0, $ticks);
        self::assertSame([3.0], $clock->sleeps);
    }

    public function testASleepIsCutWhereTheWorkIsDueAndTheWorkRunsBeforeItGoesOn(): void
    {
        $clock = new FakeClock();
        $ticker = new WaitTicker();
        $last = 0.0;
        $ticks = [];
        $ticker->attach(static function () use (&$last): float {
            return $last + 2.0;
        }, static function () use (&$last, &$ticks, $clock): void {
            $ticks[] = $clock->time;
            $last = $clock->time;
        });

        $ticker->sleep($clock, 5.0);

        self::assertSame([0.0, 2.0, 4.0, 5.0], $ticks);
        self::assertSame([2.0, 2.0, 1.0], $clock->sleeps);
    }

    public function testWorkDueNowOrOverdueDoesNotCutTheSleep(): void
    {
        $clock = new FakeClock();
        $clock->time = 10.0;
        $ticker = new WaitTicker();
        $ticks = 0;
        $ticker->attach(static fn (): float => $clock->time, static function () use (&$ticks): void {
            $ticks++;
        });

        $ticker->sleep($clock, 5.0);

        self::assertSame(2, $ticks);
        self::assertSame([5.0], $clock->sleeps);
        self::assertSame(15.0, $clock->time);
    }

    public function testASleepInsideTheWorkIsAPlainSleep(): void
    {
        $clock = new FakeClock();
        $ticker = new WaitTicker();
        $ticks = 0;
        $ticker->attach(static fn (): float => INF, static function () use (&$ticks, $ticker, $clock): void {
            $ticks++;
            if ($ticks === 1) {
                $ticker->sleep($clock, 1.0);
            }
        });

        $ticker->sleep($clock, 10.0);

        self::assertSame(2, $ticks);
        self::assertSame([1.0, 9.0], $clock->sleeps);
    }

    public function testEverySleepTicksAgainOnceTheLastTickHasReturned(): void
    {
        $clock = new FakeClock();
        $ticker = new WaitTicker();
        $ticks = 0;
        $ticker->attach(static fn (): float => INF, static function () use (&$ticks): void {
            $ticks++;
        });

        $ticker->sleep($clock, 3.0);
        $ticker->sleep($clock, 3.0);

        self::assertSame(4, $ticks);
        self::assertSame([3.0, 3.0], $clock->sleeps);
    }

    public function testTheWorkTicksAgainAfterATickThatThrew(): void
    {
        $clock = new FakeClock();
        $ticker = new WaitTicker();
        $ticks = 0;
        $ticker->attach(static fn (): float => INF, static function () use (&$ticks): void {
            $ticks++;
            if ($ticks === 1) {
                throw new \RuntimeException('tick failed');
            }
        });

        try {
            $ticker->sleep($clock, 5.0);
            self::fail('expected the tick failure');
        } catch (\RuntimeException $failure) {
            self::assertSame('tick failed', $failure->getMessage());
        }
        $ticker->sleep($clock, 5.0);

        self::assertSame(3, $ticks);
        self::assertSame([5.0], $clock->sleeps);
    }
}
