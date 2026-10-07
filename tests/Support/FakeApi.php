<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers by route instead of by order: a method and a path pattern, and one
 * answer, a list of answers (the last repeats) or a closure. A route added later wins over an earlier one.
 */
final class FakeApi implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<array{string, string, mixed, int}> */
    private array $routes = [];

    /**
     * @param  ResponseInterface|\Throwable|\Closure(RequestInterface, int): (ResponseInterface|\Throwable)|list<ResponseInterface|\Throwable|\Closure(RequestInterface, int): (ResponseInterface|\Throwable)>  $answer
     */
    public function on(string $method, string $pattern, mixed $answer): self
    {
        array_unshift($this->routes, [$method, $pattern, $answer, 0]);

        return $this;
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $path = $request->getUri()->getPath();
        foreach ($this->routes as $key => [$method, $pattern, $answer, $count]) {
            if ($method !== $request->getMethod() || preg_match('~^'.$pattern.'$~D', $path) !== 1) {
                continue;
            }
            $this->routes[$key][3] = $count + 1;
            $step = is_array($answer) ? $answer[min($count, count($answer) - 1)] : $answer;
            if ($step instanceof \Closure) {
                $step = $step($request, $count);
            }
            if ($step instanceof \Throwable) {
                throw $step;
            }
            assert($step instanceof ResponseInterface);

            return $step;
        }

        throw new \LogicException('No answer scripted for '.$request->getMethod().' '.$path);
    }

    /** @return list<string> "METHOD /path" of every request, in order */
    public function lines(): array
    {
        return array_map(static fn (RequestInterface $request): string => $request->getMethod().' '.$request->getUri()->getPath(), $this->requests);
    }

    /** @return list<RequestInterface> */
    public function to(string $method, string $pattern): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (RequestInterface $request): bool => $request->getMethod() === $method && preg_match('~^'.$pattern.'$~D', $request->getUri()->getPath()) === 1,
        ));
    }
}
