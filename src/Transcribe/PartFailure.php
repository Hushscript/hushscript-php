<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

/** A part request that failed in transit; only the kind of failure is kept. */
final class PartFailure extends \RuntimeException
{
    public static function of(#[\SensitiveParameter] \Throwable $failure): self
    {
        $context = method_exists($failure, 'getHandlerContext') ? $failure->getHandlerContext() : [];
        $errno = is_array($context) && is_int($context['errno'] ?? null) ? ' (errno '.$context['errno'].')' : '';

        return new self($failure::class.$errno);
    }
}
