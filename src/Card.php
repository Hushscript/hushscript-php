<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** A saved payment method. */
final readonly class Card
{
    /**
     * @param  int  $expMonth  0 when the method has no expiry, as `expYear`
     * @param  bool  $fastCheckoutEligible  whether `buy()` may charge it without the buyer present
     */
    private function __construct(
        public string $id,
        public string $type,
        public string $brand,
        public string $last4,
        public int $expMonth,
        public int $expYear,
        public ?string $email,
        public bool $isDefault,
        public bool $fastCheckoutEligible,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $card): self
    {
        return new self(
            $card->string('id'),
            $card->string('type'),
            $card->string('brand'),
            $card->string('last4'),
            $card->count('exp_month'),
            $card->count('exp_year'),
            $card->nullableString('email'),
            $card->bool('is_default'),
            $card->bool('fast_checkout_eligible'),
        );
    }
}
