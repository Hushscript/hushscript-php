<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class ConfigurationError extends HushscriptException
{
    public const DEFAULT_CODE = 'config_invalid';

    protected const AUTO_HINT = false;
}
