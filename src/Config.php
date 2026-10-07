<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Exception\ConfigurationError;
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

    private const OPTIONS = ['base_url', 'timeout', 'max_retries', 'log_level', 'clock', 'http_client'];

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private function __construct(
        public readonly Credentials $credentials,
        public readonly string $baseUrl,
        public readonly float $timeout,
        public readonly int $maxRetries,
        public readonly string $logLevel,
        public readonly Clock $clock,
        public readonly ?ClientInterface $httpClient,
    ) {
    }

    /**
     * An explicit option beats its environment variable.
     *
     * @param  array<string, mixed>  $options  base_url, timeout, max_retries, log_level, clock, http_client
     * @param  array<string, string>|null  $env  defaults to the process environment
     */
    public static function resolve(#[\SensitiveParameter] ?string $token = null, #[\SensitiveParameter] array $options = [], #[\SensitiveParameter] ?array $env = null): self
    {
        foreach (array_keys($options) as $name) {
            if (! in_array($name, self::OPTIONS, true)) {
                throw new ConfigurationError('Unknown option "'.Redact::text($name).'".');
            }
        }
        $resolvedToken = trim($token !== null && $token !== '' ? $token : self::env($env, self::TOKEN_ENV));
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

        $baseUrl = self::baseUrl($options['base_url'] ?? null, $env);
        $timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;
        if (! (is_int($timeout) || is_float($timeout)) || ! is_finite((float) $timeout) || $timeout <= 0) {
            throw new ConfigurationError('timeout must be a positive number of seconds.');
        }
        $maxRetries = $options['max_retries'] ?? self::DEFAULT_MAX_RETRIES;
        if (! is_int($maxRetries) || $maxRetries < 0) {
            throw new ConfigurationError('max_retries must be a non-negative integer.');
        }
        $level = $options['log_level'] ?? null;
        if ($level !== null && ! is_string($level)) {
            throw new ConfigurationError('log_level must be one of '.implode(', ', Log::LEVELS).'.');
        }
        if ($level === null) {
            $fromEnv = self::env($env, self::LOG_ENV);
            $level = $fromEnv === '' ? 'off' : $fromEnv;
        }
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

        return new self(new Credentials($resolvedToken), $baseUrl, (float) $timeout, $maxRetries, $level, $clock, $httpClient);
    }

    /**
     * @param  array<string, string>|null  $env
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
     * @param  array<string, string>|null  $env
     */
    private static function baseUrl(#[\SensitiveParameter] mixed $option, #[\SensitiveParameter] ?array $env): string
    {
        $fromEnv = self::env($env, self::BASE_URL_ENV);
        $raw = $option ?? ($fromEnv === '' ? self::DEFAULT_BASE_URL : $fromEnv);
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
