<?php

declare(strict_types=1);

namespace Hushscript\Exception;

use Hushscript\Credentials;
use Hushscript\Generated\Catalog;
use Hushscript\Generated\Scopes;
use Hushscript\Http\Routes;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds the spec 9.2 error for a non-2xx API response (spec 9.1).
 */
final class ErrorMapper
{
    /** The most a retry wait is reported as: 2^53-1, the largest integer every SDK shares. */
    private const MAX_SECONDS = 9007199254740991;

    /** @var array<string, class-string<HushscriptException>> */
    private const BY_SLUG = [
        'upload_incomplete' => UploadError::class,
        'size_exceeded' => UploadError::class,
        'agent_claim_invalid' => AuthenticationError::class,
        'unauthorized' => AuthenticationError::class,
        'pat_scope_missing' => PermissionDeniedError::class,
        'pat_forbidden' => PermissionDeniedError::class,
        'account_suspended' => PermissionDeniedError::class,
        'agent_pack_locked' => PermissionDeniedError::class,
        'pat_purchase_requires_app' => PermissionDeniedError::class,
        'pat_purchase_requires_authentication' => PermissionDeniedError::class,
        'country_restricted' => PermissionDeniedError::class,
        'signup_not_allowed' => PermissionDeniedError::class,
        'insufficient_balance' => InsufficientBalanceError::class,
        'org_insufficient_balance' => InsufficientBalanceError::class,
        'org_pool_expired' => InsufficientBalanceError::class,
        'org_plan_frozen' => InsufficientBalanceError::class,
        'wrong_state' => ConflictError::class,
        'agent_signup_pending' => ConflictError::class,
        'pat_limit' => ConflictError::class,
        'rate_limited' => RateLimitError::class,
        'too_many_active_jobs' => RateLimitError::class,
        'agent_purchase_capped' => RateLimitError::class,
        'url_target_busy' => RateLimitError::class,
    ];

    /** @var array<int, class-string<HushscriptException>> */
    private const BY_STATUS = [
        400 => ValidationError::class,
        401 => AuthenticationError::class,
        402 => InsufficientBalanceError::class,
        403 => PermissionDeniedError::class,
        404 => NotFoundError::class,
        409 => ConflictError::class,
        422 => ValidationError::class,
        429 => RateLimitError::class,
    ];

    /** @var list<array{string, string}>|null  [regex, key] */
    private static ?array $patterns = null;

    private function __construct()
    {
    }

    /** Maps a concrete request (`GET`, `/v1/jobs/abc`) to its Scopes::OPERATIONS key. */
    public static function operationKey(string $method, #[\SensitiveParameter] string $path): ?string
    {
        self::$patterns ??= array_map(
            static function (string $operation): array {
                $template = explode(' ', $operation, 2)[1];
                $regex = preg_replace('/\\\\\{[^}\/]+\\\\\}/', '[^/]+', preg_quote($template, '~')) ?? $template;

                return ['~^'.$regex.'$~D', $operation];
            },
            array_keys(Scopes::OPERATIONS),
        );
        $prefix = strtoupper($method).' ';
        $path = Routes::normalize($path);
        foreach (self::$patterns as [$regex, $key]) {
            if (str_starts_with($key, $prefix) && preg_match($regex, $path) === 1) {
                return $key;
            }
        }

        return null;
    }

    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return self::wholeSeconds(trim($value));
    }

    /** A non-negative integer of any length, capped at 2^53-1 (so one above 60 stays "over 60"); anything else is absent (R71). */
    private static function wholeSeconds(string $digits): ?int
    {
        if (preg_match('/^[0-9]+$/D', $digits) !== 1) {
            return null;
        }
        $significant = ltrim($digits, '0');

        return strlen($significant) > 16 ? self::MAX_SECONDS : min((int) $significant, self::MAX_SECONDS);
    }

    public static function fromResponse(#[\SensitiveParameter] ResponseInterface $response, Credentials $credentials, string $method, #[\SensitiveParameter] string $path): HushscriptException
    {
        $status = $response->getStatusCode();
        $content = (string) $response->getBody();
        $body = self::parseBody($content);
        $slug = $body['error'] ?? null;
        $codeValue = $body['code'] ?? null;
        $numericCode = is_int($codeValue) ? $codeValue : null;
        $code = is_string($slug) && $slug !== '' ? $slug : 'http_'.$status;
        $meaning = Catalog::ENTRIES[$code]['meaning'] ?? null;
        $message = $meaning ?? "The API answered {$status} ({$code}).";
        $retryAfterHeader = $response->getHeaderLine('Retry-After');
        $retryAfter = self::parseRetryAfter($retryAfterHeader === '' ? null : $retryAfterHeader);
        $details = self::asRecord(self::snakeKeys($credentials->scrubValue(self::extraDetails($body))));
        if ($retryAfter === null) {
            $fromDetails = $details['retry_after_seconds'] ?? null;
            if (is_int($fromDetails) && $fromDetails >= 0) {
                $retryAfter = min($fromDetails, self::MAX_SECONDS);
            } elseif (is_float($fromDetails)) {
                // An integer beyond int64 decodes as a float; decoded again with big integers kept as digit strings it is exact.
                $exact = self::asRecord(self::snakeKeys(self::extraDetails(self::parseBody($content, JSON_BIGINT_AS_STRING))))['retry_after_seconds'] ?? null;
                $retryAfter = is_string($exact) ? self::wholeSeconds($exact) : null;
            }
        }
        $operation = self::operationKey($method, $path);
        $scope = $operation === null ? null : (Scopes::OPERATIONS[$operation] ?? null);
        $class = self::BY_SLUG[$code] ?? self::BY_STATUS[$status] ?? ApiError::class;
        // The wrong_state hint names a job call, so it is attached to job and upload operations only (spec 9.3).
        $hint = $code === 'wrong_state' && ! self::isJobOrUpload($path) ? '' : null;
        $error = new $class(
            $credentials->scrub($message),
            $credentials->scrub($code),
            $numericCode,
            $status,
            $hint,
            $details,
            $retryAfter,
            $scope,
        );

        return $error;
    }

    private static function isJobOrUpload(#[\SensitiveParameter] string $path): bool
    {
        return preg_match('~^/v1/(jobs|uploads)(/|$)~', $path) === 1;
    }

    /** @return array<mixed> */
    private static function parseBody(#[\SensitiveParameter] string $content, int $flags = 0): array
    {
        try {
            $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR | $flags);
        } catch (\JsonException) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    private static function extraDetails(#[\SensitiveParameter] array $body): array
    {
        $details = array_filter($body, static fn (mixed $name): bool => ! in_array($name, ['error', 'code', 'details'], true), ARRAY_FILTER_USE_KEY);
        $nested = $body['details'] ?? null;
        if (is_array($nested)) {
            foreach ($nested as $key => $value) {
                $details[$key] ??= $value;
            }
        } elseif ($nested !== null) {
            $details['details'] = $nested;
        }

        return $details;
    }

    /**
     * Spec 9.3: details keys follow the language convention (snake_case here), recursively.
     */
    public static function snakeKeys(#[\SensitiveParameter] mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[is_string($key) ? self::snake($key) : $key] = self::snakeKeys($item);
        }

        return $out;
    }

    public static function snake(string $key): string
    {
        return strtolower(preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $key) ?? $key);
    }

    /**
     * @return array<string, mixed>
     */
    private static function asRecord(#[\SensitiveParameter] mixed $value): array
    {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $out[(string) $key] = $item;
            }
        }

        return $out;
    }
}
