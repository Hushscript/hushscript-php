<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class ValidationError extends ApiError
{
    public const DEFAULT_CODE = 'bad_request';
}
