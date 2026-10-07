<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Exchange;

/**
 * The low-level layer (spec 5): any `/v1` operation with the full hardening of spec 7.
 */
final class Api
{
    public function __construct(private readonly Exchange $exchange)
    {
    }

    /**
     * Sends one request and returns the decoded JSON body (an empty array for an empty body).
     *
     * @param  array<string, scalar|list<scalar>|null>  $query  null values are skipped, booleans are sent as true/false
     * @param  array<mixed>|null  $body  encoded as JSON; an empty array is sent as {}
     * @return array<mixed>
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function request(string $method, #[\SensitiveParameter] string $path, #[\SensitiveParameter] array $query = [], #[\SensitiveParameter] ?array $body = null): array
    {
        return $this->exchange->request($method, $path, $query, $body);
    }

    public function __serialize(): array
    {
        throw new \LogicException('Api cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Api cannot be unserialized.');
    }
}
