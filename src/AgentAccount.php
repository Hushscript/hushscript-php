<?php

declare(strict_types=1);

namespace Hushscript;

/** What `Client::agentClaim()` returns. */
final readonly class AgentAccount
{
    use RawBody;

    /**
     * @param  string  $token  the account's token, shown once: write it down before anything else can fail
     * @param  list<string>  $scopes
     * @param  Balance  $balance  the pack just granted; a new account holds nothing and has no expiry in the answer
     */
    public function __construct(
        #[\SensitiveParameter] public string $token,
        public string $userId,
        public array $scopes,
        public Balance $balance,
        /** @var array<mixed> the answer as received, for `toJson()` */
        #[\SensitiveParameter] private array $raw = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['token' => Redact::MARKER, 'userId' => $this->userId, 'scopes' => $this->scopes, 'balance' => $this->balance];
    }
}
