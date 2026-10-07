<?php

declare(strict_types=1);

namespace Hushscript\Http;

/**
 * The retry table of spec 7.2 and the upload-create schedules of spec 7.5, as pure functions.
 */
final class RetryPolicy
{
    public const MAX_RETRY_AFTER = 60;

    public const BACKOFF_CAP = 8.0;

    public const BACKOFF_BASE = 0.5;

    public const TOO_MANY_ACTIVE_JOBS = 'too_many_active_jobs';

    public const RATE_LIMITED = 'rate_limited';

    public const ACTIVE_JOBS = 'active_jobs';

    public const CREATE_RATE_LIMIT_BACKOFF = [15.0, 30.0, 60.0];

    public const ACTIVE_JOBS_WAIT = 30.0;

    public const ACTIVE_JOBS_MAX_TRIES = 10;

    private const PURCHASE_RETRIES = 1;

    private function __construct() {}

    /** Full jitter: random(0, min(8 s, 0.5 s * 2^attempt)); `$attempt` counts retries from 0. */
    public static function backoffCeiling(int $attempt): float
    {
        return min(self::BACKOFF_CAP, self::BACKOFF_BASE * (2.0 ** min($attempt, 30)));
    }

    /**
     * The 429 schedule (spec 7.5) a response belongs to: `active_jobs`, `rate_limited` or null.
     */
    public static function createLimitKind(RetryClass $class, Outcome $outcome): ?string
    {
        if ($class !== RetryClass::Create || $outcome->status !== 429) {
            return null;
        }

        return $outcome->slug === self::TOO_MANY_ACTIVE_JOBS ? self::ACTIVE_JOBS : self::RATE_LIMITED;
    }

    /**
     * Whether to try again after attempt number `$attempt` (0 is the first) and how long to wait.
     * `$own429` is how many earlier responses were 429s of the same schedule as this one; the 429
     * schedules count only those, not retries of other kinds.
     *
     * @param  \Closure(float): float  $jitter  maps a ceiling to a delay in [0, ceiling]
     */
    public static function decide(RetryClass $class, int $attempt, int $maxRetries, Outcome $outcome, \Closure $jitter, ?int $own429 = null): Decision
    {
        $own = $own429 ?? $attempt;
        if (self::exhausted($class, $attempt, $own, $maxRetries, $outcome)) {
            return new Decision(false);
        }
        if ($outcome->failed) {
            return self::afterFailure($class, $attempt, $outcome, $jitter);
        }

        return self::afterResponse($class, $attempt, $own, $outcome, $jitter);
    }

    private static function exhausted(RetryClass $class, int $attempt, int $own, int $maxRetries, Outcome $outcome): bool
    {
        if (self::createLimitKind($class, $outcome) === self::ACTIVE_JOBS) {
            // Spec 7.5: ten tries whatever maxRetries says, counted over active-jobs 429s only; only
            // maxRetries 0 turns this off.
            return $maxRetries === 0 || $own >= self::ACTIVE_JOBS_MAX_TRIES - 1;
        }
        $limit = $class === RetryClass::Purchase ? min($maxRetries, self::PURCHASE_RETRIES) : $maxRetries;

        return $attempt >= $limit;
    }

    /** @param \Closure(float): float $jitter */
    private static function afterFailure(RetryClass $class, int $attempt, Outcome $outcome, \Closure $jitter): Decision
    {
        if ($class === RetryClass::Never) {
            return new Decision(false);
        }
        if ($class === RetryClass::Mutate && ! $outcome->notSent) {
            return new Decision(false);
        }

        return new Decision(true, $jitter(self::backoffCeiling($attempt)));
    }

    /** @param \Closure(float): float $jitter */
    private static function afterResponse(RetryClass $class, int $attempt, int $own, Outcome $outcome, \Closure $jitter): Decision
    {
        if ($outcome->status === null || ! self::retryableStatus($class, $outcome->status)) {
            return new Decision(false);
        }
        if ($outcome->retryAfter !== null) {
            // Retry-After beyond the ceiling is not waited for: the caller gets the error and the value.
            return $outcome->retryAfter > self::MAX_RETRY_AFTER ? new Decision(false) : new Decision(true, (float) $outcome->retryAfter);
        }
        if (self::createLimitKind($class, $outcome) !== null) {
            if ($outcome->slug === self::TOO_MANY_ACTIVE_JOBS) {
                return new Decision(true, self::ACTIVE_JOBS_WAIT);
            }

            return $own >= count(self::CREATE_RATE_LIMIT_BACKOFF) ? new Decision(false) : new Decision(true, self::CREATE_RATE_LIMIT_BACKOFF[$own]);
        }

        return new Decision(true, $jitter(self::backoffCeiling($attempt)));
    }

    private static function retryableStatus(RetryClass $class, int $status): bool
    {
        return match ($class) {
            RetryClass::Safe => in_array($status, [429, 500, 502, 503, 504], true),
            RetryClass::Part, RetryClass::Complete, RetryClass::Create => $status === 429 || ($status >= 500 && $status < 600),
            RetryClass::Purchase => in_array($status, [502, 503], true),
            RetryClass::Never, RetryClass::Mutate => false,
        };
    }
}
