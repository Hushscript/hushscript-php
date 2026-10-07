<?php

declare(strict_types=1);

namespace Hushscript;

use GuzzleHttp\Client as GuzzleClient;
use Hushscript\Http\RetryMiddleware;
use Psr\Http\Client\ClientInterface;

/**
 * Entry point of the SDK. The token never appears in any representation of this object.
 */
final class Client
{
    private readonly Config $config;

    private readonly Api $api;

    /**
     * @param  array<string, mixed>  $options  base_url, timeout, max_retries, log_level, clock, http_client (a PSR-18 client)
     *
     * @throws \Hushscript\Exception\ConfigurationError
     */
    public function __construct(#[\SensitiveParameter] ?string $token = null, #[\SensitiveParameter] array $options = [])
    {
        $this->config = Config::resolve($token, $options);
        $inner = $this->config->httpClient === null
            ? self::defaultHttpClient($this->config->timeout)
            : self::withoutRedirects($this->config->httpClient);
        $transport = new RetryMiddleware(
            $inner,
            $this->config->credentials,
            $this->config->baseUrl,
            $this->config->clock,
            new Log($this->config->logLevel),
            $this->config->maxRetries,
        );
        $this->api = new Api($transport, $this->config->credentials, $this->config->baseUrl);
    }

    public function api(): Api
    {
        return $this->api;
    }

    /**
     * Redirects are never followed (R55). A client that can be reconfigured, like Symfony's
     * HttpClient, is told so; any other client must already behave that way (README).
     */
    private static function withoutRedirects(ClientInterface $client): ClientInterface
    {
        if (! method_exists($client, 'withOptions')) {
            return $client;
        }
        $configured = $client->withOptions(['max_redirects' => 0]);

        return $configured instanceof ClientInterface ? $configured : $client;
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

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->config->baseUrl, 'token' => Redact::MARKER];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Client cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Client cannot be unserialized.');
    }
}
