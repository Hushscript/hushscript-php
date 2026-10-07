<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\BadBody;
use Hushscript\Http\Body;

/** What a purchase left the account with, read from the purchase answer alone. */
final readonly class Purchase
{
    /**
     * @param  int  $seconds  the balance after the purchase settled: spendable now, holds already deducted
     * @param  int  $minutes  `seconds / 60`, rounded toward zero
     */
    private function __construct(
        public int $seconds,
        public int $minutes,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $body): self
    {
        if ($body->string('status') !== 'ok') {
            throw new BadBody('purchase response (status: expected ok)');
        }
        $seconds = $body->integer('balance_seconds');

        return new self($seconds, intdiv($seconds, 60));
    }
}
