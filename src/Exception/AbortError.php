<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class AbortError extends HushscriptException
{
    public const DEFAULT_CODE = 'aborted';
}
