<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class InsufficientBalanceError extends ApiError
{
    public const DEFAULT_CODE = 'insufficient_balance';
}
