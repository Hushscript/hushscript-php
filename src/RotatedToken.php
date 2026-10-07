<?php

declare(strict_types=1);

namespace Hushscript;

/** What `$hs->agent()->rotateToken()` returns. */
final readonly class RotatedToken
{
    /**
     * @param  string  $token  the successor token, shown once; the client already sends it
     * @param  list<string>  $scopes
     * @param  ?\DateTimeImmutable  $expiresAt  null when the token never expires
     */
    public function __construct(
        #[\SensitiveParameter] public string $token,
        #[\SensitiveParameter] public string $tokenId,
        public array $scopes,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['token' => Redact::MARKER, 'tokenId' => $this->tokenId, 'scopes' => $this->scopes, 'expiresAt' => $this->expiresAt];
    }
}
