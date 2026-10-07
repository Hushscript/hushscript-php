<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class UnsupportedMediaError extends PreflightError
{
    public const DEFAULT_CODE = 'unsupported_media';
}
