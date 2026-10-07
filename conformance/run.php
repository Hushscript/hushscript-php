<?php

declare(strict_types=1);

/*
 * Runs the conformance scenarios against the mock (the conformance repo's runner contract).
 *
 * Only the ops in OPS are mapped so far (the friendly layer's reads; billing and agent come later). A scenario that needs any other op is reported as SKIP and
 * does not fail the run; CONFORMANCE_STRICT=1 turns every skip into a failure, which is what the
 * contract asks for once the friendly layer is complete.
 *
 * PHP is single-threaded, so the contract's concurrent driver is folded into the injected clock. It
 * does two things. When the SDK sleeps (its own waits: backoff, pacing, polling) the clock advances
 * itself and the mock's clock by the same amount. While the upload waits on part requests that are
 * already in flight, the SDK's loop calls idle() between looks at its transfers (IdleClock), and the
 * clock does what the driver does in the contract: when every request in flight is one the mock is
 * holding until the clock moves (inFlight == pendingHolds, read from GET /__state), it advances both
 * clocks to the sooner of the SDK's next own event (the heartbeat, a retry) and the mock's next
 * release (nextHoldAt); a request that is in flight and not held is real work, so it waits for it.
 * A held response is delivered before a timer due at the same instant because the SDK looks at its
 * transfers (and so receives the response) before it looks at the heartbeat.
 */

namespace Hushscript\Conformance;

use GuzzleHttp\Client as Http;
use Hushscript\Client;
use Hushscript\Transcript;
use Hushscript\Exception\HushscriptException;
use Hushscript\Http\Body;
use Hushscript\IdleClock;
use Hushscript\Transcribe\Probe;
use Hushscript\Transcribe\Sniff;
use Hushscript\Transcribe\Source;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/goldens.php';

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

    public function bytes(string $path): string
    {
        return (string) $this->http->get($path)->getBody();
    }

    /** @return array<mixed> */
    private static function decode(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}

/**
 * Time that moves only when the SDK sleeps or waits on held requests: the mock's clock and the SDK's
 * are the same number (see the header).
 */
final class VirtualClock implements IdleClock
{
    private const STALL_SECONDS = 20.0;

    public float $time = 0.0;

    private ?float $stalledSince = null;

    public function __construct(private readonly Mock $mock)
    {
    }

    #[\Override]
    public function now(): float
    {
        return $this->time;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->advance($seconds);
    }

    #[\Override]
    public function idle(int $inFlight, float $deadline): void
    {
        $state = $this->mock->get('/__state');
        $holds = is_int($state['pendingHolds'] ?? null) ? $state['pendingHolds'] : 0;
        $release = $state['nextHoldAt'] ?? null;
        if ($inFlight > $holds) {
            // A request that is out and not held is work the SDK waits for in real time.
            $this->stalledSince = null;
            usleep(2000);

            return;
        }
        $target = min($deadline, is_int($release) || is_float($release) ? (float) $release : INF);
        if (! is_finite($target)) {
            $this->stalledSince ??= microtime(true);
            if (microtime(true) - $this->stalledSince > self::STALL_SECONDS) {
                throw new \RuntimeException('SDK stalled: nothing in flight is due and the SDK has no event to wait for');
            }
            usleep(5000);

            return;
        }
        $this->stalledSince = null;
        $this->advance($target - $this->time);
    }

    private function advance(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $this->mock->post('/__clock', ['advance' => $seconds]);
        $this->time += $seconds;
    }
}

/**
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
        $actual = get_object_vars($error)[$name] ?? null;
        $present = $value === '<present>' ? ! in_array($actual, [null, '', []], true) : $actual === $value;
        if (! $present) {
            $problems[] = "fields.{$name}: expected ".json_encode($value).', got '.json_encode($actual);
        }
    }
    $surface = surface($error);
    $excluded = $expected['messageExcludes'] ?? [];
    foreach (is_array($excluded) ? $excluded : [] as $text) {
        if (! is_string($text)) {
            continue;
        }
        $needle = $text === '$TOKEN' ? TEST_TOKEN : $text;
        if (str_contains($surface, $needle)) {
            $problems[] = 'the error surface contains '.json_encode($text);
        }
    }

    return $problems;
}

/** @return array<string, mixed> */
function balance(Client $hs): array
{
    $balance = $hs->balance();

    return [
        'seconds' => $balance->seconds,
        'minutes' => $balance->minutes,
        'heldSeconds' => $balance->heldSeconds,
        'expiresAt' => $balance->expiresAt?->format('Y-m-d\TH:i:sP'),
    ];
}

/** Fixture files fetched from the mock into a scratch directory, once each. */
final class Files
{
    /** @var array<string, float> */
    public array $durations = [];

    public function __construct(private readonly Mock $mock, private readonly string $root)
    {
    }

    public function path(string $name): string
    {
        $name = str_contains($name, '/') ? $name : "media/{$name}";
        $path = "{$this->root}/{$name}";
        if (! is_file($path)) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o700, true);
            }
            file_put_contents($path, $this->mock->bytes("/__files/fixtures/{$name}"));
        }

        return $path;
    }

    /** @param  array<mixed>  $call */
    public function prepare(array $call): void
    {
        foreach ([$call['prior'] ?? null, $call] as $step) {
            $args = is_array($step) && is_array($step['args'] ?? null) ? $step['args'] : [];
            if (isset($args['file']) && is_string($args['file'])) {
                $this->path($args['file']);
            }
        }
        if (($call['op'] ?? null) === 'probe') {
            $listing = $this->mock->get('/__files/fixtures/media/durations.json');
            foreach ($listing as $name => $seconds) {
                if (is_string($name) && (is_int($seconds) || is_float($seconds))) {
                    $this->durations[$name] = (float) $seconds;
                    $this->path($name);
                }
            }
        }
    }
}

/**
 * @param  array<mixed>  $args
 * @return array<string, mixed>
 */
function options(array $args): array
{
    $options = is_array($args['options'] ?? null) ? $args['options'] : [];
    $out = [];
    foreach ($options as $key => $value) {
        $out[snake((string) $key)] = $value;
    }

    return $out;
}

/** @return array<string, mixed> */
function transcriptResult(Transcript $transcript): array
{
    return [
        'id' => $transcript->id,
        'title' => $transcript->title,
        'language' => $transcript->language,
        'durationSeconds' => $transcript->durationSeconds,
        'utteranceCount' => count($transcript->utterances),
        'text' => $transcript->toText(),
        'raw' => $transcript->toJson(),
    ];
}

/**
 * Every transcript fixture through every formatter, against the golden of the same name; the files come from the mock.
 *
 * @return array{checked: int, mismatches: list<string>}
 */
function format(Mock $mock): array
{
    $transcripts = [];
    $mismatches = [];
    $goldens = Goldens::all();
    foreach ($goldens as $golden) {
        $transcripts[$golden->transcript] ??= Goldens::fromJson($mock->bytes("/__files/fixtures/transcripts/{$golden->transcript}.json"));
        if ($golden->render($transcripts[$golden->transcript]) !== $mock->bytes("/__files/fixtures/exports/{$golden->file}")) {
            $mismatches[] = $golden->file;
        }
    }

    return ['checked' => count($goldens), 'mismatches' => $mismatches];
}

/**
 * @param  array<mixed>  $args
 * @return array<string, mixed>
 */
function listTranscripts(Client $hs, array $args): array
{
    $options = is_int($args['pageSize'] ?? null) ? ['page_size' => $args['pageSize']] : [];
    $count = 0;
    $first = null;
    $last = null;
    foreach ($hs->transcripts()->list($options) as $summary) {
        $first ??= $summary->id;
        $last = $summary->id;
        $count++;
    }

    return ['count' => $count, 'firstId' => $first, 'lastId' => $last];
}

/**
 * @param  array<mixed>  $args
 * @return array<string, mixed>
 */
function probe(array $args, Files $files): array
{
    $tolerance = is_int($args['toleranceSeconds'] ?? null) || is_float($args['toleranceSeconds'] ?? null) ? (float) $args['toleranceSeconds'] : 1.0;
    $refuse = is_array($args['refuse'] ?? null) ? $args['refuse'] : [];
    $failures = [];
    $refused = 0;
    foreach ($files->durations as $name => $expected) {
        $source = Source::open($files->path($name));
        if (in_array($name, $refuse, true)) {
            // R46: the sniffer turns these away before any probe runs.
            if (Sniff::format($source->head()) === null) {
                $refused++;
            } else {
                $failures[] = ['file' => $name, 'expected' => 'refused', 'got' => 'sniffed'];
            }

            continue;
        }
        try {
            $got = Probe::seconds($source);
        } catch (HushscriptException) {
            $got = null;
        }
        if ($got === null || abs($got - $expected) > $tolerance) {
            $failures[] = ['file' => $name, 'expected' => $expected, 'got' => $got];
        }
    }

    return ['checked' => count($files->durations) - count($refuse), 'refused' => $refused, 'failures' => $failures];
}

const OPS = ['balance', 'transcribe', 'resume', 'probe', 'format', 'listTranscripts', 'getTranscript'];

/**
 * @param  array<mixed>  $step
 * @return array<string, mixed>
 */
function perform(Client $hs, Mock $mock, array $step, Files $files, ?string $token): array
{
    $args = is_array($step['args'] ?? null) ? $step['args'] : [];
    $file = is_string($args['file'] ?? null) ? $files->path($args['file']) : '';

    return match ($step['op'] ?? null) {
        'balance' => balance($hs),
        'transcribe' => transcriptResult($hs->transcribe($file, options($args))),
        'resume' => transcriptResult($hs->resume($token ?? '', $file, options($args))),
        'probe' => probe($args, $files),
        'format' => format($mock),
        'listTranscripts' => listTranscripts($hs, $args),
        'getTranscript' => transcriptResult($hs->transcripts()->get(is_string($args['id'] ?? null) ? $args['id'] : '')),
        default => throw new \LogicException('unmapped op'),
    };
}

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
function runScenario(Mock $mock, string $name, string $scratch): array
{
    $scenario = $mock->get("/__files/scenarios/{$name}.json");
    $call = is_array($scenario['call'] ?? null) ? $scenario['call'] : [];
    $expect = is_array($scenario['expect'] ?? null) ? $scenario['expect'] : [];
    $missing = unmapped($call);
    if ($missing !== []) {
        return ['SKIP', 'op '.implode(', ', $missing).' is not mapped yet'];
    }
    $files = new Files($mock, $scratch);
    $files->prepare($call);
    $mock->post('/__scenario', ['name' => $name]);
    $clock = new VirtualClock($mock);
    $hs = new Client(TEST_TOKEN, ['base_url' => BASE_URL, 'clock' => $clock]);
    $problems = [];
    $token = null;
    try {
        $prior = $call['prior'] ?? null;
        if (is_array($prior)) {
            try {
                perform($hs, $mock, $prior, $files, null);
                $problems[] = 'the prior call returned; it should have failed';
            } catch (\Throwable $error) {
                $wanted = is_array($prior['expectError'] ?? null) ? $prior['expectError'] : [];
                $problems = [...$problems, ...checkError($wanted, $error)];
                $token = $error instanceof HushscriptException ? ($error->resumeToken ?? null) : null;
            }
        }
        $mock->post('/__mark');
        $result = perform($hs, $mock, $call, $files, $token);
        if (isset($expect['error'])) {
            $problems[] = 'expected an error, the call returned';
        } elseif (isset($expect['result']) && is_array($expect['result'])) {
            $problems = [...$problems, ...subset($expect['result'], $result)];
        }
    } catch (\Throwable $error) {
        if (isset($expect['error']) && is_array($expect['error'])) {
            $problems = [...$problems, ...checkError($expect['error'], $error)];
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
    $mock = new Mock();
    $scratch = sys_get_temp_dir().'/hushscript-conformance-'.getmypid();
    $names = $mock->get('/__scenarios')['scenarios'] ?? [];
    if (! is_array($names) || $names === []) {
        fwrite(STDERR, "the mock lists no scenarios\n");

        return 1;
    }
    foreach (array_filter($names, is_string(...)) as $name) {
        [$status, $reason] = runScenario($mock, $name, $scratch);
        if ($status === 'SKIP' && $strict) {
            [$status, $reason] = ['FAIL', "skipped in strict mode: {$reason}"];
        }
        $counts[$status]++;
        echo $status.' '.$name.($reason === '' ? '' : ": {$reason}")."\n";
    }
    $leftover = glob($scratch.'/media/*');
    foreach (is_array($leftover) ? $leftover : [] as $file) {
        unlink($file);
    }
    @rmdir($scratch.'/media');
    @rmdir($scratch);
    echo "{$counts['PASS']} passed, {$counts['FAIL']} failed, {$counts['SKIP']} skipped\n";

    return $counts['FAIL'] > 0 || $counts['PASS'] === 0 ? 1 : 0;
}

exit(main());
