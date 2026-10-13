<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

/**
 * A CPU-time limit for a test. A test that loops forever, or dies of a fatal error, while armed ends
 * the run with exit status 1, a failure, instead of hanging until an outer timeout or crashing.
 */
final class Deadline
{
    private static bool $armed = false;

    private static bool $registered = false;

    public static function arm(int $seconds = 4): void
    {
        // Coverage runs (`just test`, Infection's initial run) are many times slower under Xdebug path
        // coverage; the limit is for mutant runs, which collect no coverage.
        $xdebugModes = function_exists('xdebug_info') ? xdebug_info('mode') : [];
        if (is_array($xdebugModes) && in_array('coverage', $xdebugModes, true)) {
            return;
        }
        if (! self::$registered) {
            register_shutdown_function(static function (): void {
                if (self::$armed) {
                    fwrite(STDERR, 'Deadline: the test did not finish ('.(error_get_last()['message'] ?? 'no error').")\n");
                    exit(1);
                }
            });
            self::$registered = true;
        }
        self::$armed = true;
        set_time_limit($seconds);
    }

    public static function disarm(): void
    {
        set_time_limit(0);
        self::$armed = false;
    }
}
