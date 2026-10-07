# hushscript-php

Official PHP SDK for the [Hushscript](https://hushscript.com) transcription API.

This is the core client: configuration, a hardened HTTP layer (retries, rate-limit pacing, per-attempt
authorization, token redaction), typed errors with hints, and a generic call for every `/v1`
operation. The friendly layer (`transcribe`, `balance`, `buy` and the rest of the SDK spec) follows in
the next release.

## Requirements

- PHP 8.2 or newer
- `guzzlehttp/guzzle` ^7 (installed by Composer)

```sh
composer require hushscript/hushscript
```

## Quick start

Create a token in the Hushscript app under Account → MCP, then:

```php
use Hushscript\Client;

$hs = new Client(); // reads HUSHSCRIPT_TOKEN
$account = $hs->api()->request('GET', '/v1/account');

echo $account['balance_seconds'];
```

`$hs->api()->request(string $method, string $path, array $query = [], ?array $body = null): array`
returns the decoded JSON body. Non-2xx answers raise a `Hushscript\Exception\*` error.

## Configuration

| Option | Env var | Default |
|---|---|---|
| token (first argument) | `HUSHSCRIPT_TOKEN` | none |
| `base_url` | `HUSHSCRIPT_BASE_URL` | `https://api.hushscript.com` |
| `timeout` | none | 60 s |
| `max_retries` | none | 3 |
| `log_level` | `HUSHSCRIPT_LOG` | `off` (`debug` logs one metadata line per attempt to standard error) |
| `http_client` | none | a PSR-18 client; Guzzle by default |
| `clock` | none | `Hushscript\Clock`, for tests |

An explicit option beats its environment variable. The base URL must be `https://`, or `http://` to
`localhost`, `127.0.0.1` or `[::1]`, with no path.

A custom `http_client` must not follow redirects and must return 4xx and 5xx answers as responses.
The SDK still adds the retry and authorization rules around it, and sends the token only to the
configured API origin.

## Errors

Every error extends `Hushscript\Exception\HushscriptException` and carries `errorCode`,
`numericCode`, `status`, `hint`, `details` and `retryAfter`. The subclasses follow the SDK spec:
`ConfigurationError`, `PreflightError`, `ApiError` (with `AuthenticationError`, `PermissionDeniedError`,
`InsufficientBalanceError`, `NotFoundError`, `ConflictError`, `ValidationError`, `RateLimitError`),
`NetworkError`, `OutcomeUnknownError` and the rest.

A request that mints a credential (agent signup, claim, token rotation) is never replayed. If its
response is lost the SDK raises `OutcomeUnknownError` with a hint on what to do next.

## Privacy

No telemetry. The token never appears in `var_dump`, `print_r`, `var_export`, `json_encode`,
exception messages or traces, and `serialize()` of the client throws.

## Development

Everything runs in containers through [just](https://github.com/casey/just): `just test`, `just lint`,
`just conformance`, `just build`, `just ci`. Use `just test 8.5` for the latest PHP.
