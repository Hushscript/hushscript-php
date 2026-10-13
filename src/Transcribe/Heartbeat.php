<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Clock;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\ConflictError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Log;

/**
 * Keeps an uploading job alive: a ping every 120 s from create until complete (spec 8.5). PHP has
 * no background thread, so the upload loop calls `tick()` whenever it looks at its transfers and
 * `deadline()` tells it, and an injected clock, how long it may wait before the next ping is due.
 *
 * A failed ping was already retried by the transport and is otherwise ignored, whatever it raised:
 * only a clean `wrong_state` (the job moved on) or `unauthorized` (kept for the upload to raise)
 * ends the beat.
 */
final class Heartbeat
{
    public const INTERVAL = 120.0;

    private float $last;

    private bool $stopped = false;

    private bool $beating = false;

    private ?AuthenticationError $failure = null;

    /**
     * @param  \Closure(): void  $ping
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly \Closure $ping,
        private readonly ?Log $log = null,
    ) {
        $this->last = $clock->now();
    }

    /** The clock time the next ping is due; INF once the beat has ended. */
    public function deadline(): float
    {
        return $this->stopped ? INF : $this->last + self::INTERVAL;
    }

    /**
     * Sends the ping when 120 s have passed since the last one, or will have passed `$lookahead`
     * seconds from now: a caller about to block that long pings before it does.
     */
    public function tick(float $lookahead = 0.0): void
    {
        if (! $this->stopped && $this->clock->now() + $lookahead >= $this->last + self::INTERVAL) {
            $this->beat();
        }
    }

    /** Sends a ping now (the one before `complete`), unless the beat has ended. */
    public function beat(): void
    {
        if ($this->stopped || $this->beating) {
            return;
        }
        // Scheduled from the moment it is sent, so a slow ping does not make the next one burst.
        $this->last = $this->clock->now();
        $this->beating = true;
        try {
            ($this->ping)();
        } catch (AuthenticationError $failure) {
            $this->failure = $failure;
            $this->stopped = true;
        } catch (ConflictError $failure) {
            if ($failure->errorCode === 'wrong_state') {
                $this->stopped = true;
            }
        } catch (HushscriptException) {
            // Already retried by the transport; the next ping tries again.
        } catch (\Throwable $failure) {
            // A ping that breaks must not end the upload.
            $this->log?->note('heartbeat ping failed ('.$failure::class.')');
        } finally {
            $this->beating = false;
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function raiseIfFailed(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
