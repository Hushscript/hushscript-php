<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Http\Pacing;
use Hushscript\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class PacingTest extends TestCase
{
    public function testFourSlotsAreFreeThenTheFifthWaitsForTheFirstToAge(): void
    {
        $clock = new FakeClock();
        $pacing = new Pacing($clock);

        foreach ([0.0, 10.0, 10.0, 10.0] as $advance) {
            $clock->time += $advance;
            self::assertSame(0.0, $pacing->reserve());
        }
        $clock->time = 30.0;

        self::assertSame(30.0, $pacing->reserve());
    }

    public function testASlotIsBookedForTheFutureSoAnotherCallerWaitsBehindIt(): void
    {
        $clock = new FakeClock();
        $pacing = new Pacing($clock, 1, 60.0);

        self::assertSame(0.0, $pacing->reserve());
        self::assertSame(60.0, $pacing->reserve());
        self::assertSame(120.0, $pacing->reserve());
    }

    public function testSlotsAgeOutOfTheWindow(): void
    {
        $clock = new FakeClock();
        $pacing = new Pacing($clock);
        for ($i = 0; $i < 4; $i++) {
            $pacing->reserve();
        }
        $clock->time = 60.0;

        self::assertSame(0.0, $pacing->reserve());
    }

    public function testASlotExactlyOneWindowOldIsFree(): void
    {
        $clock = new FakeClock();
        $pacing = new Pacing($clock, 1, 60.0);
        $pacing->reserve();
        $clock->time = 59.9;

        self::assertEqualsWithDelta(0.1, $pacing->reserve(), 1e-9);
    }
}
