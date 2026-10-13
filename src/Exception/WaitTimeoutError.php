<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class WaitTimeoutError extends HushscriptException
{
    public const DEFAULT_CODE = 'wait_timeout';
}
