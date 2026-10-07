<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client with an immutable withOptions(), the way Symfony's HttpClient has one. */
final class OptionsHttp implements ClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $optionCalls = [];

    /** @var list<bool> for each request, whether the client that sent it came from withOptions() */
    public array $sentConfigured = [];

    private function __construct(private readonly FakeHttp $answers, private readonly ?self $origin, private readonly bool $configured)
    {
    }

    public static function answering(FakeHttp $answers): self
    {
        return new self($answers, null, false);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): self
    {
        $this->optionCalls[] = $options;

        return new self($this->answers, $this->origin ?? $this, true);
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $origin = $this->origin ?? $this;
        $origin->sentConfigured[] = $this->configured;

        return $this->answers->sendRequest($request);
    }
}
