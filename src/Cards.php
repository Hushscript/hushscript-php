<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** The saved payment methods and which one is the default. */
final readonly class Cards
{
    /** @param list<Card> $cards */
    private function __construct(
        public ?string $defaultId,
        public array $cards,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $body): self
    {
        return new self($body->nullableString('default_id'), array_map(Card::fromApi(...), $body->list('methods')));
    }
}
