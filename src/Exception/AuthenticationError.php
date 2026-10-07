<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class AuthenticationError extends ApiError
{
    public const DEFAULT_CODE = 'unauthorized';
}
