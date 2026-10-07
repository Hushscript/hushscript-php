# hushscript-php

Official PHP SDK for the [Hushscript](https://hushscript.com) transcription API.

This is the core client: configuration, a hardened HTTP layer (retries, rate-limit pacing, per-attempt
authorization, token redaction), typed errors with hints, a generic call for every `/v1` operation and
the transcribe pipeline (`transcribe`, `resume`, `jobs()->wait`). The rest of the friendly layer
(`balance`, `buy` and the other resources of the SDK spec) follows in the next release.

## Requirements

- PHP 8.2 or newer, with `ext-json` and `ext-mbstring`
- `guzzlehttp/guzzle` ^7 and `james-heinrich/getid3` (installed by Composer)
- `ext-curl` for concurrent part uploads (without it, or with a client of your own, parts go one at a time)

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

## Transcribing

```php
$transcript = $hs->transcribe('/path/to/call.mp3', [
    'language' => 'en',
    'wait_timeout' => 1800,
    'on_progress' => fn (array $progress) => print_r($progress),
]);
```

`transcribe` checks the file (format by its first bytes, duration with getID3 and never less than
the true duration), uploads it in parts, waits for the job and returns the transcript as the API sent
it. Options use snake_case: `title`, `language`, `speakers`, `translate_to`, `keyterms`, `prompt`,
`medical`, `multichannel`, `auto_insights`, `matter_code`, `duration_seconds`, `idempotency_key`,
`concurrency`, `wait_timeout`, `on_progress`, `on_resume_token`.

When the call fails after the upload was created, the error carries `idempotencyKey` and
`resumeToken`. A wait that runs out raises `WaitTimeoutError` with the token:

```php
try {
    $hs->transcribe($path, ['wait_timeout' => 60]);
} catch (\Hushscript\Exception\WaitTimeoutError $timeout) {
    $transcript = $hs->resume($timeout->resumeToken, $path);
}
```

`resume($token, $path)` reads the job first. A job that is past uploading needs no file; one that is
still uploading is continued with the same file (a different size is refused before any request).
`$hs->jobs()->wait($jobId, ['wait_timeout' => 600])` polls any job until it is done.

PHP has no background threads. The upload loop owns the heartbeat (a ping at least every 120 s while
parts are in flight) and runs part requests on its own cURL multi handle, so a part that stalls for
minutes does not stop the heartbeat. With your own `http_client`, parts go through it one at a time
(no `Authorization` header is added to signed part URLs) and the heartbeat runs before every part,
early when the longest part so far could outlast the interval. The gap is bounded by one part's
duration, which is at most the 300 s part timeout: the first part, or a part slower than the ones
before it, can leave a gap longer than 120 s. The `part_transport` option (an instance
of `Hushscript\Transcribe\PartTransport`) replaces how parts are sent; a clock that also implements
`Hushscript\IdleClock` lets tests move time while requests are in flight.

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
| `part_transport` | none | advanced: a `Hushscript\Transcribe\PartTransport` for part uploads |

An explicit option beats its environment variable. The base URL must be `https://`, or `http://` to
`localhost`, `127.0.0.1` or `[::1]`, with no path.

A custom `http_client` must not follow redirects and must return 4xx and 5xx answers as responses.
The SDK still adds the retry and authorization rules around it, and sends the token only to the
configured API origin. If the client has a `withOptions()` method (Symfony's HttpClient does), the SDK
calls `withOptions(['max_redirects' => 0])` on it; any other client must be configured that way by you.

Requests that must never be replayed (account claim, credential rotation, signup, unkeyed creates,
purchases and every other mutation) have to go out over a fresh connection. libcurl otherwise replays
a request on a kept-alive connection that the server closed after the body was sent, and the API would
see it twice. With the default Guzzle client, and with any `GuzzleHttp\ClientInterface` you pass, the
SDK requests a fresh, non-reusable connection for those calls. A custom PSR-18 client that is not a
Guzzle client must itself never retry or replay a request on a reused connection, or must open a new
connection per request; the SDK cannot enforce this on a foreign client.

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
