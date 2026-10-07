<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class UploadError extends HushscriptException
{
    public const DEFAULT_CODE = 'upload_incomplete';
}
