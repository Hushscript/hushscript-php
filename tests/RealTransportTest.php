<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Hushscript\Client;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\Rig;
use Hushscript\Tests\Support\ScriptedServer;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The default transport against real sockets (spec 7.4, R54): libcurl itself replays a request on a
 * reused keep-alive connection that dies after the body was sent, so a request that must not be
 * replayed goes over a fresh connection.
 */
final class RealTransportTest extends TestCase
{
    private const CLAIM = '/v1/agent/accounts/acc_1/claim';

    private ?ScriptedServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    /** A client over the default Guzzle transport whose first call has already opened a kept-alive connection. */
    private function warmClient(ScriptedServer $server): Client
    {
        $client = new Client(Tokens::TEST, ['base_url' => $server->baseUrl(), 'clock' => new FakeClock(), 'timeout' => 10]);
        self::assertSame(['ok' => true], $client->api()->request('GET', '/v1/account'));

        return $client;
    }

    public function testAClaimOnADeadKeepAliveConnectionIsSentOnceAndEndsOutcomeUnknown(): void
    {
        $server = $this->server = ScriptedServer::start();
        $client = $this->warmClient($server);

        try {
            $client->api()->request('POST', self::CLAIM, body: ['claim_secret' => 'cs_0123456789abcdef']);
            self::fail('expected an OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame('outcome_unknown', $error->errorCode);
        }

        $requests = $server->stop();
        $this->server = null;
        self::assertSame(['GET /v1/account', 'POST '.self::CLAIM], $requests, 'libcurl replayed the claim on a second connection');
    }

    public function testACustodyPostOnADeadKeepAliveConnectionIsSentOnce(): void
    {
        $server = $this->server = ScriptedServer::start();
        $client = $this->warmClient($server);

        try {
            $client->api()->request('POST', '/v1/org/custody', body: ['mode' => 'managed']);
            self::fail('expected a NetworkError');
        } catch (NetworkError $error) {
            self::assertStringContainsString('POST /v1/org/custody', $error->getMessage());
        }

        $requests = $server->stop();
        $this->server = null;
        self::assertSame(['GET /v1/account', 'POST /v1/org/custody'], $requests);
    }

    public function testAClaimRefusedAtConnectIsNotSent(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($listener);
        $port = explode(':', (string) stream_socket_get_name($listener, false))[1];
        fclose($listener);
        $client = new Client(Tokens::TEST, ['base_url' => 'http://127.0.0.1:'.$port, 'clock' => new FakeClock(), 'timeout' => 10]);

        try {
            $client->api()->request('POST', self::CLAIM, body: ['claim_secret' => 'cs_0123456789abcdef']);
            self::fail('expected a NetworkError');
        } catch (OutcomeUnknownError) {
            self::fail('a refused connect proves the claim never went out');
        } catch (NetworkError) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, bool}> */
    public static function routesAndConnections(): iterable
    {
        yield 'safe read' => ['GET', '/v1/account', [], false];
        yield 'part upload' => ['PUT', '/v1/uploads/u1/parts/1', [], false];
        yield 'complete' => ['POST', '/v1/uploads/u1/complete', [], false];
        yield 'keyed create' => ['POST', '/v1/uploads', ['size_bytes' => 1, 'idempotency_key' => '0123456789abcdef0123456789abcdef'], false];
        yield 'unkeyed create' => ['POST', '/v1/uploads', ['size_bytes' => 1], true];
        yield 'purchase' => ['POST', '/v1/billing/purchase', ['pack' => '45min'], true];
        yield 'claim' => ['POST', self::CLAIM, ['claim_secret' => 'x'], true];
        yield 'rotate' => ['POST', '/v1/agent/credentials/rotate', [], true];
        yield 'signup' => ['POST', '/v1/agent/accounts', ['x' => 1], true];
        yield 'mutation' => ['POST', '/v1/org/members', ['x' => 1], true];
        yield 'delete' => ['DELETE', '/v1/org/members/m1', [], true];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('routesAndConnections')]
    public function testOnlyRequestsThatMustNotBeReplayedForceAFreshConnection(string $method, string $path, array $body, bool $fresh): void
    {
        /** @var list<array<string, mixed>> $seen */
        $seen = [];
        $handler = new MockHandler([static fn (RequestInterface $request, array $options): Response => new Response(200, [], '{}')]);
        $stack = HandlerStack::create($handler);
        $stack->push(static function (callable $next) use (&$seen): callable {
            return static function (RequestInterface $request, array $options) use ($next, &$seen) {
                $seen[] = $options;

                return $next($request, $options);
            };
        });
        $guzzle = new GuzzleClient(['handler' => $stack, 'allow_redirects' => true, 'http_errors' => true]);
        $rig = new Rig(new Support\FakeHttp(), inner: $guzzle);

        $rig->api->request($method, $path, body: $body === [] ? null : $body);

        self::assertCount(1, $seen);
        $curl = $seen[0]['curl'] ?? [];
        self::assertIsArray($curl);
        self::assertSame($fresh, ($curl[CURLOPT_FRESH_CONNECT] ?? false) === true);
        self::assertSame($fresh, ($curl[CURLOPT_FORBID_REUSE] ?? false) === true);
        self::assertFalse($seen[0]['allow_redirects'], 'redirects are never followed');
        self::assertFalse($seen[0]['http_errors'], 'error statuses come back as responses');
    }
}
