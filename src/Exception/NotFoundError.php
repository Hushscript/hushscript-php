<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class NotFoundError extends ApiError
{
    public const DEFAULT_CODE = 'not_found';
}
