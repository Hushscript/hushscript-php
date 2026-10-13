<?php

declare(strict_types=1);

namespace Hushscript\Http;

/**
 * A 2xx body that decoded as JSON but is not what the SDK needs. The message names the field and
 * what was expected there, never a value; `Exchange` turns it into `bad_response` with the status.
 *
 * @internal
 */
final class BadBody extends \UnexpectedValueException
{
}
