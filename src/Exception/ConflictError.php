<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class ConflictError extends ApiError
{
    public const DEFAULT_CODE = 'wrong_state';
}
