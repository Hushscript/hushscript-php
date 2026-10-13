<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Redact;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * A part request that failed: the original's class and message, with token-shaped strings and URL queries redacted
 * (R86, R96), and whether it was a transport failure. The original, which holds the request, is not kept.
 */
final class PartFailure extends \RuntimeException
{
    private bool $transport = true;

    public static function of(#[\SensitiveParameter] \Throwable $failure): self
    {
        $context = method_exists($failure, 'getHandlerContext') ? $failure->getHandlerContext() : [];
        $errno = is_array($context) && is_int($context['errno'] ?? null) ? ' (errno '.$context['errno'].')' : '';
        $part = new self(Redact::text($failure::class.': '.$failure->getMessage()).$errno);
        $part->transport = $failure instanceof NetworkExceptionInterface || $failure instanceof RequestExceptionInterface;

        return $part;
    }

    /** False for an error that is not a transport failure (R86): it is never retried and reaches the caller as `unexpected_error`. */
    public function transport(): bool
    {
        return $this->transport;
    }
}
