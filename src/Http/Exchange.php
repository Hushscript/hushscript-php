<?php

declare(strict_types=1);

namespace Hushscript\Http;

use GuzzleHttp\Psr7\Request;
use Hushscript\Credentials;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\ErrorMapper;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Exception\PreflightError;
use Hushscript\Redact;
use Psr\Http\Client\ClientInterface;

/**
 * One API request through the hardened transport (spec 7): the shared core of the low-level `Api`
 * and of the friendly layer. Not reachable from `Client`.
 *
 * @internal
 */
final class Exchange
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
     * @throws HushscriptException
     */
    public function request(string $method, #[\SensitiveParameter] string $path, #[\SensitiveParameter] array $query = [], #[\SensitiveParameter] ?array $body = null): array
    {
        return $this->exchange($method, $path, $query, $body, false) ?? [];
    }

    /**
     * One request whose answer is read by `$parse`: the one boundary where a 2xx body is checked. A body
     * the parser cannot use (`BadBody`, or any other failure of the parser) is `bad_response` carrying the
     * actual status; no field is read before the parser has accepted the whole body.
     *
     * @template T
     *
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<mixed>|null  $body
     * @param  \Closure(Body, int): T  $parse  given the body and the actual 2xx status
     * @return T
     *
     * @throws HushscriptException
     */
    public function fetch(string $method, #[\SensitiveParameter] string $path, #[\SensitiveParameter] array $query, #[\SensitiveParameter] ?array $body, \Closure $parse): mixed
    {
        $status = 0;
        $decoded = $this->exchange($method, $path, $query, $body, false, $status);
        try {
            return $parse(Body::of($decoded, 'response'), $status);
        } catch (HushscriptException $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw self::badResponse($status);
        }
    }

    /**
     * Like `fetch`, for a route whose 200 may be a JSON `null` (the part-urls page of a job that takes no
     * direct URLs): the null comes back as null, without calling `$parse`.
     *
     * @template T
     *
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  \Closure(Body, int): T  $parse
     * @return T|null
     *
     * @throws HushscriptException
     */
    public function fetchOrNull(string $method, #[\SensitiveParameter] string $path, #[\SensitiveParameter] array $query, \Closure $parse): mixed
    {
        $status = 0;
        $decoded = $this->exchange($method, $path, $query, null, true, $status);
        if ($decoded === null) {
            return null;
        }
        try {
            return $parse(Body::of($decoded, 'response'), $status);
        } catch (HushscriptException $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw self::badResponse($status);
        }
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<mixed>|null  $body
     * @return array<mixed>|null
     */
    private function exchange(string $method, #[\SensitiveParameter] string $path, #[\SensitiveParameter] array $query, #[\SensitiveParameter] ?array $body, bool $nullable, int &$answered = 0): ?array
    {
        [$status, $content] = $this->send($method, $path, $query, $body);
        $answered = $status;
        if (trim($content) === '') {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::badResponse($status);
        }
        if ($decoded === null && $nullable) {
            return null;
        }
        if (! is_array($decoded)) {
            throw self::badResponse($status);
        }

        return $decoded;
    }

    /**
     * Sends one request and returns the 2xx status with the body text, undecoded. Every failure up to here is an
     * SDK error: a non-2xx answer is its mapped error, and a failure of the SDK's own after the request went out is
     * `unexpected_error`, or `OutcomeUnknownError` on a route whose answer cannot be had again (spec 7.4).
     *
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<mixed>|null  $body
     * @return array{int, string}
     *
     * @throws HushscriptException
     */
    public function send(string $method, #[\SensitiveParameter] string $path, #[\SensitiveParameter] array $query = [], #[\SensitiveParameter] ?array $body = null): array
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

        try {
            $response = $this->http->sendRequest($request);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw ErrorMapper::fromResponse($response, $this->credentials, $method, $path);
            }

            return [$status, (string) $response->getBody()];
        } catch (HushscriptException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            $route = Routes::unrecoverable($method, $path);
            if ($route !== null) {
                throw $this->lost($route, $failure);
            }
            throw $this->unexpected($failure);
        }
    }

    /** A failure of the SDK's own after a claim, rotation or signup went out: as lost as an answer that never came. */
    private function lost(string $route, #[\SensitiveParameter] \Throwable $failure): OutcomeUnknownError
    {
        return new OutcomeUnknownError(
            'The answer could not be used, so its outcome is unknown.',
            hint: Routes::UNKNOWN_HINTS[$route] ?? '',
            previous: new \RuntimeException($this->credentials->scrub($failure::class.': '.$failure->getMessage())),
        );
    }

    /** A failure of the SDK's own after the request was sent: the original is kept as a redacted cause. */
    public function unexpected(#[\SensitiveParameter] \Throwable $failure): HushscriptException
    {
        return self::wrapUnexpected($failure, $this->credentials);
    }

    public static function wrapUnexpected(#[\SensitiveParameter] \Throwable $failure, Credentials $credentials): HushscriptException
    {
        return new HushscriptException(
            'The SDK failed after the request was sent.',
            'unexpected_error',
            previous: new \RuntimeException($credentials->scrub($failure::class.': '.$failure->getMessage())),
        );
    }

    public static function badResponse(int $status): ApiError
    {
        return new ApiError('The API answered '.$status.' with a body the SDK cannot read.', 'bad_response', status: $status);
    }

    private static function checkPath(#[\SensitiveParameter] string $path): void
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
    private static function queryString(#[\SensitiveParameter] array $query): string
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
}
