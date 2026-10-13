<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

/**
 * Runs the caller's callbacks and remembers what they threw, so a failure the caller raised can be told
 * from one the SDK raised: the caller's propagates unchanged, the same object (spec 9.2).
 */
final class Callbacks
{
    /** @var \WeakMap<\Throwable, true> */
    private \WeakMap $raised;

    public function __construct()
    {
        $this->raised = new \WeakMap();
    }

    /**
     * @template T
     *
     * @param  \Closure(T): void  $callback
     * @param  T  $argument
     */
    public function call(\Closure $callback, #[\SensitiveParameter] mixed $argument): void
    {
        try {
            $callback($argument);
        } catch (\Throwable $failure) {
            $this->raised[$failure] = true;

            throw $failure;
        }
    }

    public function raised(#[\SensitiveParameter] \Throwable $failure): bool
    {
        return isset($this->raised[$failure]);
    }
}
