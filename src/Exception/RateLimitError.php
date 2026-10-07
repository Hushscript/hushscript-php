<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class RateLimitError extends ApiError
{
    public const DEFAULT_CODE = 'rate_limited';
}
