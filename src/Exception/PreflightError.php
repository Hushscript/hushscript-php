<?php

declare(strict_types=1);

namespace Hushscript\Exception;

class PreflightError extends HushscriptException
{
    public const DEFAULT_CODE = 'preflight_failed';
}
