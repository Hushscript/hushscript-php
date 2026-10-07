<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class ResumeMismatchError extends PreflightError
{
    public const DEFAULT_CODE = 'resume_file_mismatch';
}
