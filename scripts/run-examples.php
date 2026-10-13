<?php

declare(strict_types=1);

/*
 * Runs every program in examples/ against the conformance mock the container shares a network with.
 *
 * Each example gets a fresh mock scenario, a sample recording and the arguments it documents. It runs only
 * through `just examples`.
 */

use GuzzleHttp\Client as Http;
use Hushscript\Config;

require __DIR__.'/../vendor/autoload.php';

const TIMEOUT_SECONDS = 600;

/**
 * Runs one example and returns its exit code.
 *
 * @param  list<string>  $arguments
 */
function runExample(string $example, array $arguments, string $baseUrl, string $token): int
{
    $process = proc_open(
        [PHP_BINARY, $example, ...$arguments],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes,
        null,
        [...getenv(), 'HUSHSCRIPT_BASE_URL' => $baseUrl, 'HUSHSCRIPT_TOKEN' => $token],
    );
    if (! is_resource($process)) {
        return 1;
    }
    $deadline = microtime(true) + TIMEOUT_SECONDS;
    while (true) {
        $status = proc_get_status($process);
        if (! $status['running']) {
            proc_close($process);

            return $status['exitcode'];
        }
        if (microtime(true) > $deadline) {
            proc_terminate($process);
            proc_close($process);

            return 124;
        }
        usleep(50_000);
    }
}

function main(): int
{
    $baseUrl = getenv('HUSHSCRIPT_BASE_URL');
    if ($baseUrl === false || $baseUrl === '') {
        $baseUrl = 'http://127.0.0.1:4010';
    }
    // The mock accepts any hsr token.
    $token = 'hsr1:eu:pat.'.str_repeat('0', 32).'.'.str_repeat('0', 64);
    $examples = glob(__DIR__.'/../examples/*.php');
    if ($examples === false) {
        $examples = [];
    }
    sort($examples);
    if ($examples === []) {
        fwrite(STDERR, "no examples found\n");

        return 1;
    }
    $http = new Http(['base_uri' => $baseUrl, 'timeout' => 30]);
    $work = sys_get_temp_dir().'/hushscript-examples-'.getmypid();
    mkdir($work.'/recordings', 0o700, true);
    $media = (string) $http->get('/__files/fixtures/media/cbr-20s.mp3')->getBody();
    $sample = $work.'/sample.mp3';
    foreach ([$sample, $work.'/recordings/one.mp3', $work.'/recordings/two.mp3'] as $name) {
        file_put_contents($name, $media);
    }
    $tokenFile = $work.'/agent-token';
    // What each example is run with: the arguments it documents.
    $arguments = [
        'balance' => [],
        'transcribe' => [$sample],
        'export_srt' => [$sample, $work.'/meeting.srt'],
        'batch_folder' => [$work.'/recordings', $work.'/transcripts'],
        'buy' => ['300min', '--yes'],
        'agent_signup' => ['--terms', '2026-10-01', '--token-file', $tokenFile],
    ];
    $failed = false;
    foreach ($examples as $example) {
        $name = basename($example, '.php');
        $http->post('/__scenario', ['json' => ['mock' => ['account' => ['balance_seconds' => 360000], 'completeState' => 'done']]]);
        echo "== {$name}\n";
        $code = runExample($example, $arguments[$name] ?? [], $baseUrl, $token);
        if ($code !== 0) {
            echo "FAILED {$name}: exit {$code}\n";
            $failed = true;
        }
    }
    $saved = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
    if (! is_file($tokenFile) || (fileperms($tokenFile) & 0o777) !== 0o600 || ! Config::acceptableToken($saved)) {
        echo "FAILED agent_signup: the token file is missing, not mode 0600 or not a token\n";
        $failed = true;
    }
    $leftovers = glob($work.'/{,recordings/,transcripts/}*', GLOB_BRACE);
    foreach ($leftovers === false ? [] : $leftovers as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$work.'/recordings', $work.'/transcripts', $work] as $directory) {
        @rmdir($directory);
    }

    return $failed ? 1 : 0;
}

exit(main());
