<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\ApiError;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\ConflictError;
use Hushscript\Log;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Transcribe\Heartbeat;
use PHPUnit\Framework\TestCase;

final class HeartbeatTest extends TestCase
{
    private int $pings = 0;

    public function testLookaheadPingsBeforeBlockingAndSchedulesFromTheSendTime(): void
    {
        $clock = new FakeClock();
        $clock->time = 10.0;
        $beat = new Heartbeat($clock, function () use ($clock): void {
            $this->pings++;
            $clock->time += 5.0;
        });

        $clock->time = 100.0;
        $beat->tick(29.9);
        self::assertSame(0, $this->pings);
        $beat->tick(30.0);
        self::assertSame(1, $this->pings);
        self::assertSame(105.0, $clock->time);
        self::assertSame(220.0, $beat->deadline());
        $beat->stop();
        $beat->tick(1000.0);
        self::assertSame(1, $this->pings);
    }

    public function testPingCannotReenterItselfAndTheGuardResetsAfterFailure(): void
    {
        $beat = null;
        $beat = new Heartbeat(new FakeClock(), function () use (&$beat): void {
            $this->pings++;
            self::assertInstanceOf(Heartbeat::class, $beat);
            if ($this->pings === 1) {
                $beat->beat();
                throw new \RuntimeException('ping failed');
            }
        });

        $beat->beat();
        self::assertSame(1, $this->pings);
        $beat->beat();
        self::assertSame(2, $this->pings);
        $beat->raiseIfFailed();
    }

    public function testItPingsOnlyOnceTheIntervalHasPassed(): void
    {
        $clock = new FakeClock();
        $beat = new Heartbeat($clock, function (): void {
            $this->pings++;
        });

        $clock->time = 119.9;
        $beat->tick();
        self::assertSame(0, $this->pings);
        self::assertSame(120.0, $beat->deadline());

        $clock->time = 120.0;
        $beat->tick();
        self::assertSame(1, $this->pings);
        self::assertSame(240.0, $beat->deadline(), 'the next ping is scheduled from the one just sent');
    }

    public function testBeatSendsNowAndStopEndsIt(): void
    {
        $clock = new FakeClock();
        $beat = new Heartbeat($clock, function (): void {
            $this->pings++;
        });

        $beat->beat();
        $beat->stop();
        $beat->beat();
        $clock->time = 500.0;
        $beat->tick();

        self::assertSame(1, $this->pings);
        self::assertSame(INF, $beat->deadline());
    }

    public function testACleanWrongStateEndsTheBeatQuietly(): void
    {
        $clock = new FakeClock();
        $beat = new Heartbeat($clock, function (): void {
            $this->pings++;

            throw new ConflictError('The job moved on.', 'wrong_state');
        });

        $beat->beat();
        $beat->beat();
        $beat->raiseIfFailed();

        self::assertSame(1, $this->pings);
    }

    public function testAnotherConflictDoesNotEndTheBeat(): void
    {
        $beat = new Heartbeat(new FakeClock(), function (): void {
            $this->pings++;

            throw new ConflictError('Busy.', 'something_else');
        });

        $beat->beat();
        $beat->beat();

        self::assertSame(2, $this->pings);
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
        $beat = new Heartbeat($clock, function (): void {
            $this->pings++;

            throw $this->pings === 1 ? new ApiError('Down.', 'upstream', status: 503) : new \RuntimeException('boom');
        });

        $beat->beat();
        $clock->time = 120.0;
        $beat->tick();
        $beat->raiseIfFailed();

        self::assertSame(2, $this->pings);
        self::assertSame(240.0, $beat->deadline());
    }

    public function testAPingThatBreaksIsLoggedByItsClass(): void
    {
        $lines = [];
        $beat = new Heartbeat(new FakeClock(), static function (): void {
            throw new \RuntimeException('boom');
        }, new Log('debug', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        $beat->beat();

        self::assertSame(['hushscript heartbeat ping failed (RuntimeException)'], $lines);
    }

    public function testThePingCanBeSentAgainAfterTheFailureLogItselfThrew(): void
    {
        $pings = 0;
        $beat = new Heartbeat(new FakeClock(), static function () use (&$pings): void {
            $pings++;

            throw new \RuntimeException('boom');
        }, new Log('debug', static function (): void {
            throw new \LogicException('log down');
        }));

        try {
            $beat->beat();
            self::fail('expected the log failure');
        } catch (\LogicException $failure) {
            self::assertSame('log down', $failure->getMessage());
        }
        try {
            $beat->beat();
        } catch (\LogicException) {
        }

        self::assertSame(2, $pings);
    }
}
