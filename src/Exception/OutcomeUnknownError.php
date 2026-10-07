<?php

declare(strict_types=1);

namespace Hushscript\Exception;

/**
 * A request that cannot be repeated was sent and its answer was lost or unusable (spec 7.4). When the answer of
 * a claim or rotation carried a token before it failed, `receivedToken()` returns it, so the credential the server
 * shows once is not lost. The token is held outside the object, so no dump, export, serialization, trace or
 * string form of the error can show it; treat the returned string like any token.
 */
final class OutcomeUnknownError extends HushscriptException
{
    public const DEFAULT_CODE = 'outcome_unknown';

    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $received = null;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?int $numericCode = null,
        ?int $status = null,
        ?string $hint = null,
        array $details = [],
        ?int $retryAfter = null,
        ?string $scope = null,
        ?\Throwable $previous = null,
        #[\SensitiveParameter] ?string $receivedToken = null,
    ) {
        parent::__construct($message, $errorCode, $numericCode, $status, $hint, $details, $retryAfter, $scope, $previous);
        if ($receivedToken !== null && trim($receivedToken) !== '') {
            self::$received ??= new \WeakMap();
            self::$received[$this] = $receivedToken;
        }
    }

    /** The token the failed answer carried, or null when it carried none. */
    public function receivedToken(): ?string
    {
        return self::$received[$this] ?? null;
    }
}
