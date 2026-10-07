<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client that answers from a FakeHttp; subclasses add their own withOptions() variants. */
abstract class BareHttp implements ClientInterface
{
    /** @var list<string> */
    public array $optionCalls = [];

    public function __construct(public readonly FakeHttp $answers)
    {
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->answers->sendRequest($request);
    }
}
