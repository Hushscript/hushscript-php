<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Hushscript\Transcribe\GuzzleMultiTransport;
use Hushscript\Transcribe\PartFailure;
use Hushscript\Transcribe\PartTransport;
use Hushscript\Transcribe\SyncTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/** The two real part transports against a loopback server (the pipeline tests use a fake one). */
final class PartTransportTest extends TestCase
{
    /** @var resource|null */
    private $process;

    /** @var resource|null */
    private $output;

    private int $port = 0;

    protected function tearDown(): void
    {
        if ($this->output !== null) {
            fclose($this->output);
        }
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->output = $this->process = null;
    }

    private function serve(): string
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/../Support/etag-server.php'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        $this->process = $process;
        $this->output = $pipes[1];
        stream_set_timeout($pipes[1], 10);
        $line = (string) fgets($pipes[1]);
        self::assertSame(1, preg_match('/^PORT (\d+)/', $line, $match));
        $this->port = (int) $match[1];

        return 'http://127.0.0.1:'.$this->port;
    }

    /** @return iterable<string, array{\Closure(): PartTransport}> */
    public static function transports(): iterable
    {
        yield 'multi' => [static fn (): PartTransport => new GuzzleMultiTransport(5.0)];
        yield 'sync' => [static fn (): PartTransport => new SyncTransport(new GuzzleClient())];
    }

    private static function settle(PartTransport $transport, PromiseInterface $promise): void
    {
        $deadline = microtime(true) + 10;
        while ($promise->getState() === PromiseInterface::PENDING && microtime(true) < $deadline) {
            $transport->tick();
        }
    }

    /** @param  \Closure(): PartTransport  $make */
    #[DataProvider('transports')]
    public function testSeveralPartsAreAnsweredAndCarryTheirETags(\Closure $make): void
    {
        $base = $this->serve();
        $transport = $make();

        $promises = [];
        foreach (['aaaaaaaa', 'bbbbbbbb', 'cccccccc'] as $body) {
            $promises[$body] = $transport->send(new Request('PUT', $base.'/part?X-Amz-Signature=SECRET', ['Content-Length' => (string) strlen($body)], $body), 5.0);
        }
        foreach ($promises as $promise) {
            self::settle($transport, $promise);
        }

        foreach ($promises as $body => $promise) {
            self::assertSame(PromiseInterface::FULFILLED, $promise->getState());
            $response = $promise->wait();
            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('"etag-'.$body.'"', $response->getHeaderLine('ETag'));
        }
    }

    /** @param  \Closure(): PartTransport  $make */
    #[DataProvider('transports')]
    public function testAConnectionFailureRejectsWithAFailureThatHoldsNoUrl(\Closure $make): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($listener);
        $port = explode(':', (string) stream_socket_get_name($listener, false))[1];
        fclose($listener);
        $transport = $make();

        $promise = $transport->send(new Request('PUT', 'http://127.0.0.1:'.$port.'/part?X-Amz-Signature=SECRET', [], 'x'), 5.0);
        self::settle($transport, $promise);

        self::assertSame(PromiseInterface::REJECTED, $promise->getState());
        try {
            $promise->wait();
            self::fail('expected a failure');
        } catch (PartFailure $failure) { // @phpstan-ignore catch.neverThrown
            self::assertStringNotContainsString('SECRET', (string) $failure);
            self::assertStringNotContainsString('127.0.0.1', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testTheMultiTransportIsAvailableWithCurl(): void
    {
        self::assertTrue(GuzzleMultiTransport::available());
    }

    public function testTheSyncTransportHandsThePartTimeoutToAGuzzleClient(): void
    {
        $seen = [];
        $client = new GuzzleClient(['handler' => static function (\Psr\Http\Message\RequestInterface $request, array $options) use (&$seen): PromiseInterface {
            $seen = $options;

            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200));
        }]);

        (new SyncTransport($client))->send(new Request('PUT', 'http://127.0.0.1:1/part'), 300.0);

        self::assertSame(300.0, $seen['timeout'] ?? null);
        self::assertFalse($seen['allow_redirects'] ?? true);
    }
}
