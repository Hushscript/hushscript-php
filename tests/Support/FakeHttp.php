<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client that answers from a script and records what it was sent. */
final class FakeHttp implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> the Authorization header of each request, or "" */
    public array $authorizations = [];

    /** @var list<ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface> */
    private array $script;

    /**
     * @param  list<ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface>  $script
     */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    /** @param ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface $step */
    public function push(mixed $step): self
    {
        $this->script[] = $step;

        return $this;
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->authorizations[] = $request->getHeaderLine('Authorization');
        $step = array_shift($this->script);
        if ($step === null) {
            throw new \LogicException('The fake client has no more scripted answers.');
        }
        if ($step instanceof \Throwable) {
            throw $step;
        }
        if ($step instanceof \Closure) {
            return $step($request);
        }

        return $step;
    }

    /**
     * @param  array<string, mixed>|string  $body
     * @param  array<string, string>  $headers
     */
    public static function json(int $status, array|string $body = [], array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function count(): int
    {
        return count($this->requests);
    }
}
