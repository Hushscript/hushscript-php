<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class PermissionDeniedError extends ApiError
{
    public const DEFAULT_CODE = 'pat_forbidden';
}
