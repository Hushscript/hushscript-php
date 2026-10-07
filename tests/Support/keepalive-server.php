<?php

declare(strict_types=1);

// A loopback HTTP server for the transport tests, run as a child process. It answers the first
// request on a keep-alive connection; every later request is read in full (headers and body),
// logged to standard output, and the connection is closed without a reply. Several connections
// are served at once, so a fresh connection never waits behind an idle kept-alive one.

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}
echo 'PORT '.explode(':', (string) stream_socket_get_name($server, false))[1]."\n";
fflush(STDOUT);

/** @var array<int, resource> $connections */
$connections = [];
/** @var array<int, string> $buffers */
$buffers = [];
$count = 0;
$deadline = time() + 60;

while (time() < $deadline) {
    $read = [$server, ...array_values($connections)];
    $write = null;
    $except = null;
    if (stream_select($read, $write, $except, 1) < 1) {
        continue;
    }
    foreach ($read as $stream) {
        if ($stream === $server) {
            $accepted = stream_socket_accept($server, 0);
            if ($accepted !== false) {
                $connections[(int) $accepted] = $accepted;
                $buffers[(int) $accepted] = '';
            }

            continue;
        }
        $id = (int) $stream;
        $chunk = fread($stream, 65536);
        if ($chunk === false || $chunk === '') {
            fclose($stream);
            unset($connections[$id], $buffers[$id]);

            continue;
        }
        $buffers[$id] .= $chunk;
        $end = strpos($buffers[$id], "\r\n\r\n");
        if ($end === false) {
            continue;
        }
        $head = substr($buffers[$id], 0, $end);
        $length = preg_match('/^content-length:\s*(\d+)/im', $head, $match) === 1 ? (int) $match[1] : 0;
        if (strlen($buffers[$id]) < $end + 4 + $length) {
            continue;
        }
        $count++;
        echo 'REQ '.$count.' '.strtok($head, "\r\n").' '.$length."\n";
        fflush(STDOUT);
        $buffers[$id] = '';
        if ($count === 1) {
            fwrite($stream, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 11\r\nConnection: keep-alive\r\n\r\n{\"ok\":true}");

            continue;
        }
        fclose($stream);
        unset($connections[$id], $buffers[$id]);
    }
}
