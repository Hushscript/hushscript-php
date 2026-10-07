<?php

declare(strict_types=1);

/*
 * Runs the conformance scenarios against the mock (the conformance repo's runner contract).
 *
 * Only the ops in OPS are mapped so far. A scenario that needs any other op is reported as SKIP and
 * does not fail the run; CONFORMANCE_STRICT=1 turns every skip into a failure, which is what the
 * contract asks for once the friendly layer is complete.
 *
 * PHP is single-threaded, so the contract's concurrent driver does not port directly: the injected
 * clock advances itself and the mock's clock in the same step when the SDK sleeps. That covers every
 * scenario whose waits are the SDK's own. Scenarios where the mock holds a response until the clock
 * moves (heartbeat-cadence) need a driver that ticks while a request is in flight (curl_multi).
 */

namespace Hushscript\Conformance;

use GuzzleHttp\Client as Http;
use Hushscript\Client;
use Hushscript\Clock;
use Hushscript\Exception\HushscriptException;

require __DIR__.'/../vendor/autoload.php';

const BASE_URL = 'http://127.0.0.1:4010';
const TEST_TOKEN = 'hsr1:eu:pat.'.'0123456789abcdef0123456789abcdef'.'.'.'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

final class Mock
{
    private readonly Http $http;

    public function __construct()
    {
        $this->http = new Http(['base_uri' => BASE_URL, 'timeout' => 30, 'http_errors' => true]);
    }

    /** @return array<mixed> */
    public function get(string $path): array
    {
        return self::decode((string) $this->http->get($path)->getBody());
    }

    /** @param array<string, mixed> $json */
    public function post(string $path, array $json = []): void
    {
        $this->http->post($path, $json === [] ? [] : ['json' => $json]);
    }

    /** @return array<mixed> */
    private static function decode(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}

/** Time that moves only when the SDK sleeps: the mock's clock and the SDK's are the same number. */
final class VirtualClock implements Clock
{
    public float $time = 0.0;

    public function __construct(private readonly Mock $mock) {}

    #[\Override]
    public function now(): float
    {
        return $this->time;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $this->mock->post('/__clock', ['advance' => $seconds]);
        $this->time += $seconds;
    }
}

/**
 * @param  array<mixed>  $expected
 * @return list<string>
 */
function subset(mixed $expected, mixed $actual, string $path = 'result'): array
{
    if (is_array($expected) && ! array_is_list($expected)) {
        if (! is_array($actual)) {
            return ["{$path}: expected an object, got ".json_encode($actual)];
        }
        $problems = [];
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                $problems[] = "{$path}.{$key}: missing";
            } else {
                $problems = [...$problems, ...subset($value, $actual[$key], "{$path}.{$key}")];
            }
        }

        return $problems;
    }
    if (is_array($expected)) {
        if (! is_array($actual) || count($actual) !== count($expected)) {
            return ["{$path}: expected ".json_encode($expected).', got '.json_encode($actual)];
        }
        $problems = [];
        foreach (array_values($expected) as $index => $value) {
            $problems = [...$problems, ...subset($value, array_values($actual)[$index], "{$path}[{$index}]")];
        }

        return $problems;
    }

    return $expected === $actual || (is_numeric($expected) && is_numeric($actual) && (float) $expected === (float) $actual)
        ? []
        : ["{$path}: expected ".json_encode($expected).', got '.json_encode($actual)];
}

function snake(string $key): string
{
    return strtolower(preg_replace('/([A-Z])/', '_$1', $key) ?? $key);
}

/** @return array<mixed> */
function snakeKeys(mixed $value): array
{
    $out = [];
    foreach (is_array($value) ? $value : [] as $key => $item) {
        $out[is_string($key) ? snake($key) : $key] = is_array($item) ? snakeKeys($item) : $item;
    }

    return $out;
}

/** Everything a caller could print or log about an error, the whole `previous` chain included. */
function surface(\Throwable $error): string
{
    $parts = [];
    for ($link = $error; $link !== null; $link = $link->getPrevious()) {
        $parts[] = $link->getMessage();
        $parts[] = (string) $link;
        $parts[] = print_r($link, true);
        $parts[] = var_export($link->getTrace(), true);
        if ($link instanceof HushscriptException) {
            $parts[] = (string) $link->hint;
            $parts[] = (string) json_encode($link->details);
        }
    }

    return implode("\n", $parts);
}

/**
 * @param  array<mixed>  $expected
 * @return list<string>
 */
function checkError(array $expected, \Throwable $error): array
{
    if (! $error instanceof HushscriptException) {
        return ['expected an SDK error, got '.$error::class.': '.$error->getMessage()];
    }
    $problems = [];
    foreach (['code' => $error->errorCode, 'numericCode' => $error->numericCode, 'status' => $error->status, 'retryAfter' => $error->retryAfter] as $key => $actual) {
        if (array_key_exists($key, $expected) && $expected[$key] !== $actual) {
            $problems[] = "{$key}: expected ".json_encode($expected[$key]).', got '.json_encode($actual);
        }
    }
    if (isset($expected['hintContains']) && is_string($expected['hintContains']) && ! str_contains((string) $error->hint, $expected['hintContains'])) {
        $problems[] = 'hint '.json_encode($error->hint).' does not contain '.json_encode($expected['hintContains']);
    }
    $class = $expected['class'] ?? null;
    if (is_string($class)) {
        $name = $class === 'HushscriptError' ? HushscriptException::class : 'Hushscript\\Exception\\'.$class;
        if (! class_exists($name) || ! $error instanceof $name) {
            $problems[] = "class: expected {$class}, got ".$error::class;
        }
    }
    if (isset($expected['details'])) {
        $problems = [...$problems, ...subset(snakeKeys($expected['details']), $error->details, 'details')];
    }
    $fields = $expected['fields'] ?? [];
    foreach (is_array($fields) ? $fields : [] as $name => $value) {
        $actual = property_exists($error, (string) $name) ? $error->{(string) $name} : null;
        $present = $value === '<present>' ? ! in_array($actual, [null, '', []], true) : $actual === $value;
        if (! $present) {
            $problems[] = "fields.{$name}: expected ".json_encode($value).', got '.json_encode($actual);
        }
    }
    $surface = surface($error);
    $excluded = $expected['messageExcludes'] ?? [];
    foreach (is_array($excluded) ? $excluded : [] as $text) {
        $needle = $text === '$TOKEN' ? TEST_TOKEN : (string) $text;
        if (str_contains($surface, $needle)) {
            $problems[] = 'the error surface contains '.json_encode($text);
        }
    }

    return $problems;
}

/** @return array<string, mixed> */
function balance(Client $hs): array
{
    // The friendly layer arrives with the next task; until then the op reads the low-level Api.
    $account = $hs->api()->request('GET', '/v1/account');
    $seconds = is_int($account['balance_seconds'] ?? null) ? $account['balance_seconds'] : 0;
    $expires = $account['credits_expire_at'] ?? null;

    return [
        'seconds' => $seconds,
        'minutes' => intdiv($seconds, 60),
        'heldSeconds' => $account['held_seconds'] ?? 0,
        'expiresAt' => is_int($expires) ? gmdate('c', $expires) : null,
    ];
}

const OPS = ['balance'];

/**
 * @param  array<mixed>  $call
 * @return list<string>
 */
function unmapped(array $call): array
{
    $ops = [is_string($call['op'] ?? null) ? $call['op'] : '?'];
    $prior = $call['prior'] ?? null;
    if (is_array($prior) && is_string($prior['op'] ?? null)) {
        $ops[] = $prior['op'];
    }

    return array_values(array_filter($ops, static fn (string $op): bool => ! in_array($op, OPS, true)));
}

/** @return array{string, string} */
function runScenario(Mock $mock, string $name): array
{
    $scenario = $mock->get("/__files/scenarios/{$name}.json");
    $call = is_array($scenario['call'] ?? null) ? $scenario['call'] : [];
    $expect = is_array($scenario['expect'] ?? null) ? $scenario['expect'] : [];
    $missing = unmapped($call);
    if ($missing !== []) {
        return ['SKIP', 'op '.implode(', ', $missing).' is not mapped yet'];
    }
    $mock->post('/__scenario', ['name' => $name]);
    $hs = new Client(TEST_TOKEN, ['base_url' => BASE_URL, 'clock' => new VirtualClock($mock)]);
    $problems = [];
    $mock->post('/__mark');
    try {
        $result = balance($hs);
        if (isset($expect['error'])) {
            $problems[] = 'expected an error, the call returned';
        } elseif (isset($expect['result']) && is_array($expect['result'])) {
            $problems = subset($expect['result'], $result);
        }
    } catch (\Throwable $error) {
        if (isset($expect['error']) && is_array($expect['error'])) {
            $problems = checkError($expect['error'], $error);
        } else {
            $problems[] = 'raised '.$error::class.': '.$error->getMessage();
        }
    }
    $verdict = $mock->get('/__verify');
    $failures = is_array($verdict['failures'] ?? null) ? $verdict['failures'] : [];
    foreach ($failures as $failure) {
        $problems[] = 'mock: '.(is_string($failure) ? $failure : json_encode($failure));
    }

    return $problems === [] ? ['PASS', ''] : ['FAIL', implode('; ', $problems)];
}

function main(): int
{
    $strict = getenv('CONFORMANCE_STRICT') === '1';
    $counts = ['PASS' => 0, 'FAIL' => 0, 'SKIP' => 0];
    $mock = new Mock;
    $names = $mock->get('/__scenarios')['scenarios'] ?? [];
    if (! is_array($names) || $names === []) {
        fwrite(STDERR, "the mock lists no scenarios\n");

        return 1;
    }
    foreach ($names as $name) {
        [$status, $reason] = runScenario($mock, (string) $name);
        if ($status === 'SKIP' && $strict) {
            [$status, $reason] = ['FAIL', "skipped in strict mode: {$reason}"];
        }
        $counts[$status]++;
        echo $status.' '.$name.($reason === '' ? '' : ": {$reason}")."\n";
    }
    echo "{$counts['PASS']} passed, {$counts['FAIL']} failed, {$counts['SKIP']} skipped\n";

    return $counts['FAIL'] > 0 || $counts['PASS'] === 0 ? 1 : 0;
}

exit(main());
