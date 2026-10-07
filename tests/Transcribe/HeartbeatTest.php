<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\ApiError;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\ConflictError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Transcribe\Heartbeat;
use PHPUnit\Framework\TestCase;

final class HeartbeatTest extends TestCase
{
    public function testItPingsOnlyOnceTheIntervalHasPassed(): void
    {
        $clock = new FakeClock();
        $pings = 0;
        $beat = new Heartbeat($clock, static function () use (&$pings): void {
            $pings++;
        });

        $clock->time = 119.9;
        $beat->tick();
        self::assertSame(0, $pings);
        self::assertSame(120.0, $beat->deadline());

        $clock->time = 120.0;
        $beat->tick();
        self::assertSame(1, $pings);
        self::assertSame(240.0, $beat->deadline(), 'the next ping is scheduled from the one just sent');
    }

    public function testBeatSendsNowAndStopEndsIt(): void
    {
        $clock = new FakeClock();
        $pings = 0;
        $beat = new Heartbeat($clock, static function () use (&$pings): void {
            $pings++;
        });

        $beat->beat();
        $beat->stop();
        $beat->beat();
        $clock->time = 500.0;
        $beat->tick();

        self::assertSame(1, $pings);
        self::assertSame(INF, $beat->deadline());
    }

    public function testACleanWrongStateEndsTheBeatQuietly(): void
    {
        $clock = new FakeClock();
        $pings = 0;
        $beat = new Heartbeat($clock, static function () use (&$pings): void {
            $pings++;

            throw new ConflictError('The job moved on.', 'wrong_state');
        });

        $beat->beat();
        $beat->beat();
        $beat->raiseIfFailed();

        self::assertSame(1, $pings);
    }

    public function testAnotherConflictDoesNotEndTheBeat(): void
    {
        $pings = 0;
        $beat = new Heartbeat(new FakeClock(), static function () use (&$pings): void {
            $pings++;

            throw new ConflictError('Busy.', 'something_else');
        });

        $beat->beat();
        $beat->beat();

        self::assertSame(2, $pings);
    }

    public function testARefusedTokenEndsTheBeatAndIsKeptForTheUpload(): void
    {
        $failure = new AuthenticationError('Refused.');
        $beat = new Heartbeat(new FakeClock(), static function () use ($failure): void {
            throw $failure;
        });

        $beat->beat();

        self::assertSame(INF, $beat->deadline());
        try {
            $beat->raiseIfFailed();
            self::fail('expected the refusal');
        } catch (AuthenticationError $raised) {
            self::assertSame($failure, $raised);
        }
    }

    public function testOtherFailuresAreIgnoredAndTheNextPingTriesAgain(): void
    {
        $clock = new FakeClock();
        $pings = 0;
        $beat = new Heartbeat($clock, static function () use (&$pings): void {
            $pings++;

            throw $pings === 1 ? new ApiError('Down.', 'upstream', status: 503) : new \RuntimeException('boom');
        });

        $beat->beat();
        $clock->time = 120.0;
        $beat->tick();
        $beat->raiseIfFailed();

        self::assertSame(2, $pings);
        self::assertSame(240.0, $beat->deadline());
    }
}
