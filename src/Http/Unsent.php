<?php

declare(strict_types=1);

namespace Hushscript\Http;

use Hushscript\Exception\HushscriptException;

/**
 * The errors the SDK raised before the request went out (R103): a purchase that fails this way cannot have
 * charged anything, so it carries no idempotency key. Kept outside the error, so the public error has no new field.
 *
 * @internal
 */
final class Unsent
{
    /** @var \WeakMap<HushscriptException, true>|null */
    private static ?\WeakMap $marked = null;

    private function __construct()
    {
    }

    /**
     * @template T of HushscriptException
     *
     * @param  T  $error
     * @return T
     */
    public static function mark(HushscriptException $error): HushscriptException
    {
        self::$marked ??= new \WeakMap();
        self::$marked[$error] = true;

        return $error;
    }

    public static function has(HushscriptException $error): bool
    {
        return self::$marked !== null && isset(self::$marked[$error]);
    }
}
