<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Exception\ConfigurationError;
use Hushscript\Transcribe\PartTransport;
use Psr\Http\Client\ClientInterface;

/**
 * The resolved configuration of a Client (spec 3). It holds the credentials as an opaque object,
 * never the token itself.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.hushscript.com';

    public const DEFAULT_TIMEOUT = 60.0;

    public const DEFAULT_MAX_RETRIES = 3;

    public const TOKEN_ENV = 'HUSHSCRIPT_TOKEN';

    public const BASE_URL_ENV = 'HUSHSCRIPT_BASE_URL';

    public const LOG_ENV = 'HUSHSCRIPT_LOG';

    public const SETUP_HINT = 'Set HUSHSCRIPT_TOKEN or pass a token. Create one in the Hushscript app: '
        .'Account → MCP (app.hushscript.com/account/mcp).';

    private const OPTIONS = ['base_url', 'timeout', 'max_retries', 'log_level', 'clock', 'http_client', 'part_transport'];

    // What a call that needs no token takes: signup and claim are never retried and upload nothing.
    private const TOKENLESS_OPTIONS = ['base_url', 'timeout', 'log_level', 'clock', 'http_client'];

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private function __construct(
        public readonly Credentials $credentials,
        public readonly string $baseUrl,
        public readonly float $timeout,
        public readonly int $maxRetries,
        public readonly string $logLevel,
        public readonly Clock $clock,
        public readonly ?ClientInterface $httpClient,
        public readonly ?PartTransport $partTransport = null,
    ) {
    }

    /**
     * An explicit option beats its environment variable.
     *
     * @param  array<string, mixed>  $options  base_url, timeout, max_retries, log_level, clock, http_client, part_transport
     * @param  array<string, string|null>|null  $env  defaults to the process environment
     */
    public static function resolve(#[\SensitiveParameter] ?string $token = null, #[\SensitiveParameter] array $options = [], #[\SensitiveParameter] ?array $env = null): self
    {
        foreach (array_keys($options) as $name) {
            if (! in_array($name, self::OPTIONS, true)) {
                throw new ConfigurationError('Unknown option "'.Redact::text($name).'".');
            }
        }
        $resolvedToken = trim($token ?? '');
        if ($resolvedToken === '') {
            $resolvedToken = trim(self::env($env, self::TOKEN_ENV));
        }
        if ($resolvedToken === '') {
            throw new ConfigurationError('No Hushscript token was provided.', hint: self::SETUP_HINT);
        }
        if (preg_match('/^[\x21-\x7E]+$/D', $resolvedToken) !== 1) {
            // The message never echoes the token (spec 7.4).
            throw new ConfigurationError('The token contains control or non-printable characters.');
        }
        if (! str_starts_with($resolvedToken, 'hsr')) {
            throw new ConfigurationError("This doesn't look like a Hushscript token.");
        }

        return self::finish(new Credentials($resolvedToken), $options, $env);
    }

    /**
     * The configuration of a call that needs no token (agent signup and claim): HUSHSCRIPT_TOKEN is neither read
     * nor sent, and the options are the ones such a call can use.
     *
     * @param  array<string, mixed>  $options  base_url, timeout, log_level, clock, http_client
     * @param  array<string, string|null>|null  $env  defaults to the process environment
     */
    public static function tokenless(#[\SensitiveParameter] array $options = [], #[\SensitiveParameter] ?array $env = null): self
    {
        foreach (array_keys($options) as $name) {
            if (! in_array($name, self::TOKENLESS_OPTIONS, true)) {
                throw new ConfigurationError('Unknown option "'.Redact::text($name).'".');
            }
        }

        return self::finish(new Credentials(''), $options, $env);
    }

    /**
     * Whether a string is a token the client may send: printable ASCII only and the `hsr` prefix. The check every
     * token passes, at construction and after a rotation.
     */
    public static function acceptableToken(#[\SensitiveParameter] string $token): bool
    {
        return preg_match('/^[\x21-\x7E]+$/D', $token) === 1 && str_starts_with($token, 'hsr');
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string|null>|null  $env
     */
    private static function finish(Credentials $credentials, #[\SensitiveParameter] array $options, #[\SensitiveParameter] ?array $env): self
    {
        $baseUrl = self::baseUrl($options['base_url'] ?? null, $env);
        $timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;
        if (! (is_int($timeout) || is_float($timeout)) || ! is_finite((float) $timeout) || $timeout <= 0) {
            throw new ConfigurationError('timeout must be a positive number of seconds.');
        }
        $maxRetries = $options['max_retries'] ?? self::DEFAULT_MAX_RETRIES;
        if (! is_int($maxRetries) || $maxRetries < 0) {
            throw new ConfigurationError('max_retries must be a non-negative integer.');
        }
        $level = self::unsetToNull($options['log_level'] ?? null) ?? self::unsetToNull(self::env($env, self::LOG_ENV)) ?? 'off';
        if (! in_array($level, Log::LEVELS, true)) {
            throw new ConfigurationError('log_level must be one of '.implode(', ', Log::LEVELS).'.');
        }
        $clock = $options['clock'] ?? new SystemClock();
        if (! $clock instanceof Clock) {
            throw new ConfigurationError('clock must implement '.Clock::class.'.');
        }
        $httpClient = $options['http_client'] ?? null;
        if ($httpClient !== null && ! $httpClient instanceof ClientInterface) {
            throw new ConfigurationError('http_client must implement Psr\Http\Client\ClientInterface.');
        }

        $partTransport = $options['part_transport'] ?? null;
        if ($partTransport !== null && ! $partTransport instanceof PartTransport) {
            throw new ConfigurationError('part_transport must implement '.PartTransport::class.'.');
        }

        return new self($credentials, $baseUrl, (float) $timeout, $maxRetries, $level, $clock, $httpClient, $partTransport);
    }

    // Null, an empty string and a whitespace-only string all mean "not set" (R85); anything else is kept as given.
    private static function unsetToNull(#[\SensitiveParameter] mixed $value): mixed
    {
        return $value === null || (is_string($value) && trim($value) === '') ? null : $value;
    }

    /**
     * @param  array<string, string|null>|null  $env
     */
    private static function env(#[\SensitiveParameter] ?array $env, string $name): string
    {
        if ($env !== null) {
            return $env[$name] ?? '';
        }
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, string|null>|null  $env
     */
    private static function baseUrl(#[\SensitiveParameter] mixed $option, #[\SensitiveParameter] ?array $env): string
    {
        $raw = self::unsetToNull($option) ?? self::unsetToNull(self::env($env, self::BASE_URL_ENV)) ?? self::DEFAULT_BASE_URL;
        if (! is_string($raw)) {
            throw new ConfigurationError('base_url must be a string.');
        }
        $parts = parse_url($raw);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationError('base_url must be https://, or http:// to localhost, 127.0.0.1 or [::1].');
        }
        $path = $parts['path'] ?? '';
        if (($path !== '' && $path !== '/') || str_contains($raw, '?') || str_contains($raw, '#')) {
            // Requests are classified by their path relative to the base URL (spec 7.4), so it has none.
            throw new ConfigurationError('base_url must not have a path, query or fragment.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $secure = $scheme === 'https';
        $loopback = $scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true);
        if ((! $secure && ! $loopback) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ConfigurationError('base_url must be https://, or http:// to localhost, 127.0.0.1 or [::1].');
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
            'logLevel' => $this->logLevel,
            'token' => Redact::MARKER,
        ];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Config cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Config cannot be unserialized.');
    }
}
