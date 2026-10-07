<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** A minute pack as the account can see it. */
final readonly class Pack
{
    /**
     * @param  string  $id  `45min`, `300min`, `900min`, `1800min` or `6000min`
     * @param  int  $seconds  what the pack adds
     * @param  int  $minutes  `seconds / 60`, rounded toward zero
     * @param  Money  $price  in the account's own currency, as the API states it: never hard-code one
     * @param  int  $bonusMinutes  what an available referral bonus would add to this purchase; 0 when none
     * @param  bool  $available  false only for an agent account's tier-locked pack
     */
    private function __construct(
        public string $id,
        public int $seconds,
        public int $minutes,
        public Money $price,
        public int $bonusMinutes,
        public bool $available,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $pack): self
    {
        $seconds = $pack->count('seconds');

        return new self(
            $pack->string('id'),
            $seconds,
            intdiv($seconds, 60),
            Money::of($pack->integer('amount'), $pack->string('currency')),
            intdiv($pack->count('referral_bonus_seconds'), 60),
            $pack->nullableBool('available') !== false,
        );
    }
}
