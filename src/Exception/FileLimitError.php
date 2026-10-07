<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class FileLimitError extends PreflightError
{
    public const DEFAULT_CODE = 'size_out_of_range';
}
