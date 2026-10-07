<?php

declare(strict_types=1);

namespace Hushscript\Exception;

final class PaymentMethodRequiredError extends HushscriptException
{
    public const DEFAULT_CODE = 'no_default_card';
}
