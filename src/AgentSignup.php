<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/** What `Client::agentSignup()` returns. */
final readonly class AgentSignup
{
    /**
     * @param  string  $signupId  passed back to `Client::agentClaim()` verbatim
     * @param  string  $claimSecret  shown once; `Client::agentClaim()` needs it
     * @param  string  $checkoutUrl  the hosted checkout page where the account is paid for; it expires at `expiresAt`
     */
    private function __construct(
        public string $signupId,
        #[\SensitiveParameter] public string $claimSecret,
        #[\SensitiveParameter] public string $checkoutUrl,
        public Money $price,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $body): self
    {
        return new self(
            $body->string('signup_id'),
            $body->string('claim_secret'),
            $body->string('checkout_url'),
            Money::of($body->integer('amount'), $body->string('currency')),
            $body->timestamp('expires_at'),
        );
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['signupId' => $this->signupId, 'claimSecret' => Redact::MARKER, 'checkoutUrl' => $this->checkoutUrl, 'price' => $this->price, 'expiresAt' => $this->expiresAt];
    }
}
