<?php

declare(strict_types=1);

namespace Hushscript;

use GuzzleHttp\Psr7\Request;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\ErrorMapper;
use Hushscript\Exception\PreflightError;
use Psr\Http\Client\ClientInterface;

/**
 * The low-level layer (spec 5): any `/v1` operation with the full hardening of spec 7.
 */
final class Api
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly Credentials $credentials,
        private readonly string $baseUrl,
    ) {
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
    public function request(string $method, string $path, array $query = [], #[\SensitiveParameter] ?array $body = null): array
    {
        $method = strtoupper($method);
        if (! in_array($method, self::METHODS, true)) {
            throw new PreflightError('Unsupported HTTP method "'.Redact::text($method).'".', 'invalid_option');
        }
        self::checkPath($path);
        $queryString = self::queryString($query);
        $request = new Request($method, $this->baseUrl.$path.($queryString === '' ? '' : '?'.$queryString), ['Accept' => 'application/json']);
        if ($body !== null) {
            try {
                $encoded = $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                throw new PreflightError('The request body cannot be encoded as JSON.', 'invalid_option');
            }
            $request = $request->withHeader('Content-Type', 'application/json')->withBody(\GuzzleHttp\Psr7\Utils::streamFor($encoded));
        }

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw ErrorMapper::fromResponse($response, $this->credentials, $method, $path);
        }
        $content = (string) $response->getBody();
        if (trim($content) === '') {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->badResponse($status);
        }
        if (! is_array($decoded)) {
            throw $this->badResponse($status);
        }

        return $decoded;
    }

    private function badResponse(int $status): ApiError
    {
        return new ApiError('The API answered '.$status.' with a body the SDK cannot read.', 'bad_response', status: $status);
    }

    private static function checkPath(string $path): void
    {
        // The path is appended to the configured origin, so it cannot change the host; the shape check
        // keeps the retry class (decided from the actual path) honest.
        if (preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#D', $path) !== 1
            || str_contains($path, '//')
            || preg_match('~(^|/)\.\.?(/|$)~', $path) === 1
            || preg_match('~%(2e|2f|5c)~i', $path) === 1) {
            throw new PreflightError('The request path must be an absolute API path such as /v1/account.', 'invalid_option');
        }
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     */
    private static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item === null) {
                    continue;
                }
                $text = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
                $pairs[] = rawurlencode($name).'='.rawurlencode($text);
            }
        }

        return implode('&', $pairs);
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
