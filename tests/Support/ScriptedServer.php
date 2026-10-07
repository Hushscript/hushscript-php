<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

/** Runs keepalive-server.php as a child process and reads back what it received. */
final class ScriptedServer
{
    /**
     * @param  resource  $process
     * @param  resource  $output
     */
    private function __construct(private $process, private $output, public readonly int $port)
    {
    }

    public static function start(): self
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/keepalive-server.php'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start the test server.');
        }
        stream_set_timeout($pipes[1], 10);
        $line = fgets($pipes[1]);
        if ($line === false || preg_match('/^PORT (\d+)/', $line, $match) !== 1) {
            proc_terminate($process);
            throw new \RuntimeException('The test server did not report a port.');
        }

        return new self($process, $pipes[1], (int) $match[1]);
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /**
     * Stops the server and returns one entry per request it read, such as "POST /v1/org/custody".
     *
     * @return list<string>
     */
    public function stop(): array
    {
        proc_terminate($this->process);
        $log = (string) stream_get_contents($this->output);
        fclose($this->output);
        proc_close($this->process);
        $requests = [];
        foreach (explode("\n", $log) as $line) {
            if (preg_match('/^REQ \d+ (\S+ \S+) HTTP\/1\.1 \d+$/', $line, $match) === 1) {
                $requests[] = $match[1];
            }
        }

        return $requests;
    }
}
