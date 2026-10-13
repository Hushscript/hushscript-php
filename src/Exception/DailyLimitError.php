<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class DailyLimitError extends PreflightError
{
    public const DEFAULT_CODE = 'daily_limit';
}
