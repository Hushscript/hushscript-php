<?php

declare(strict_types=1);

namespace Hushscript\Http;

use GuzzleHttp\Client as GuzzleClient;
use Hushscript\Config;
use Hushscript\Log;
use Psr\Http\Client\ClientInterface;

/**
 * The hardened transport a configuration describes, built once: the HTTP client without redirects, the
 * retry middleware and the exchange the friendly layer sends through. Shared by `Client` and by the static
 * agent calls, which have no token.
 *
 * @internal
 */
final readonly class Stack
{
    private function __construct(
        public ClientInterface $inner,
        public Log $log,
        public WaitTicker $ticker,
        public Exchange $exchange,
    ) {
    }

    public static function build(Config $config): self
    {
        $log = new Log($config->logLevel);
        $inner = $config->httpClient === null
            ? self::defaultHttpClient($config->timeout)
            : self::withoutRedirects($config->httpClient, $log);
        $ticker = new WaitTicker();
        $transport = new RetryMiddleware(
            $inner,
            $config->credentials,
            $config->baseUrl,
            $config->clock,
            $log,
            $config->maxRetries,
            ticker: $ticker,
        );

        return new self($inner, $log, $ticker, new Exchange($transport, $config->credentials, $config->baseUrl));
    }

    /**
     * Redirects are never followed (R55). A client that can be reconfigured, like Symfony's
     * HttpClient, is told so; any other client must already behave that way (README). The method is
     * called only when it is public and takes one array (or untyped) argument; anything else, or a
     * failure, leaves the client as it is, because a foreign client's quirks never break construction.
     */
    private static function withoutRedirects(ClientInterface $client, Log $log): ClientInterface
    {
        if (! self::acceptsOptions($client)) {
            return $client;
        }
        try {
            $configured = $client->withOptions(['max_redirects' => 0]); // @phpstan-ignore method.notFound
        } catch (\Throwable $failure) {
            $log->note('withOptions() failed ('.$failure::class.'); the HTTP client is used as it is');

            return $client;
        }

        return $configured instanceof ClientInterface ? $configured : $client;
    }

    private static function acceptsOptions(ClientInterface $client): bool
    {
        if (! method_exists($client, 'withOptions')) {
            return false;
        }
        // method_exists() found it, so reflecting it cannot fail.
        $method = new \ReflectionMethod($client, 'withOptions');
        if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() !== 1) {
            return false;
        }
        $type = $method->getParameters()[0]->getType();

        return $type === null || ($type instanceof \ReflectionNamedType && in_array($type->getName(), ['array', 'mixed'], true));
    }

    private static function defaultHttpClient(float $timeout): ClientInterface
    {
        // Redirects are never followed and 4xx/5xx come back as responses: the SDK's transport
        // decides what to retry and builds the typed error.
        return new GuzzleClient([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }
}
