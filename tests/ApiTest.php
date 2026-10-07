<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Exception\ApiError;
use Hushscript\Exception\PreflightError;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\Rig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ApiTest extends TestCase
{
    public function testItBuildsTheUrlQueryAndJsonBody(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['a' => 1])]);
        $rig = new Rig($http);

        $result = $rig->api->request('post', '/v1/things/42', ['q' => 'a b&c', 'flag' => true, 'off' => false, 'skip' => null, 'n' => 5, 'many' => ['x', 'y']], ['name' => 'é/ü', 'nested' => ['k' => [1, 2]]]);

        self::assertSame(['a' => 1], $result);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(Rig::BASE.'/v1/things/42?q=a%20b%26c&flag=true&off=false&n=5&many=x&many=y', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('{"name":"é/ü","nested":{"k":[1,2]}}', (string) $request->getBody());
    }

    public function testAnEmptyBodyArrayIsAnEmptyObjectAndNoBodyMeansNoContentType(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, []), FakeHttp::json(200, [])]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/x', body: []);
        $rig->api->request('GET', '/v1/x');

        self::assertSame('{}', (string) $http->requests[0]->getBody());
        self::assertSame('', $http->requests[1]->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $http->requests[1]->getBody());
    }

    public function testAnEmptyOrBlank2xxBodyIsAnEmptyArray(): void
    {
        $http = new FakeHttp([new \GuzzleHttp\Psr7\Response(204), FakeHttp::json(200, "  \n")]);
        $rig = new Rig($http);

        self::assertSame([], $rig->api->request('DELETE', '/v1/x'));
        self::assertSame([], $rig->api->request('GET', '/v1/x'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function unusableBodies(): iterable
    {
        yield '200 not json' => [200, 'not json'];
        yield '200 scalar' => [200, '42'];
        yield '200 string' => [200, '"ok"'];
        yield '200 null' => [200, 'null'];
        yield '201 truncated' => [201, '{"a":'];
        yield '202 html' => [202, '<html></html>'];
    }

    #[DataProvider('unusableBodies')]
    public function testAnUnusable2xxBodyIsABadResponseWithTheActualStatus(int $status, string $body): void
    {
        $rig = new Rig(new FakeHttp([FakeHttp::json($status, $body)]));

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame(ApiError::class, $error::class);
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame($status, $error->status);
            self::assertNull($error->numericCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badPaths(): iterable
    {
        yield 'relative' => ['v1/account'];
        yield 'absolute url' => ['https://evil.example/v1/account'];
        yield 'protocol relative' => ['//evil.example/v1/account'];
        yield 'at sign host' => ['@evil.example/x'];
        yield 'query inside' => ['/v1/account?x=1'];
        yield 'fragment' => ['/v1/account#x'];
        yield 'dot dot' => ['/v1/../v1/billing/purchase'];
        yield 'single dot' => ['/v1/./account'];
        yield 'double slash' => ['/v1//account'];
        yield 'encoded slash' => ['/v1/billing%2fpurchase'];
        yield 'encoded dot' => ['/v1/%2e%2e/account'];
        yield 'backslash' => ['/v1\\account'];
        yield 'space' => ['/v1/ account'];
        yield 'newline' => ["/v1/account\r\nX: y"];
        yield 'empty' => [''];
    }

    #[DataProvider('badPaths')]
    public function testAPathThatCouldChangeTheOriginOrTheClassIsRefused(string $path): void
    {
        $http = new FakeHttp([FakeHttp::json(200, [])]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', $path);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
        }
        self::assertCount(0, $http->requests);
    }

    public function testAnUnsupportedMethodIsRefused(): void
    {
        $rig = new Rig(new FakeHttp());

        $this->expectException(PreflightError::class);
        $rig->api->request('TRACE', '/v1/account');
    }

    public function testABodyThatCannotBeEncodedIsRefusedBeforeAnyRequest(): void
    {
        $http = new FakeHttp();
        $rig = new Rig($http);

        try {
            $rig->api->request('POST', '/v1/x', body: ['bad' => "\xB1\x31"]);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
        }
        self::assertCount(0, $http->requests);
    }

    public function testNon2xxBecomesTheTypedError(): void
    {
        $rig = new Rig(new FakeHttp([FakeHttp::json(404, ['error' => 'not_found', 'code' => 1000])]));

        try {
            $rig->api->request('GET', '/v1/transcripts/missing');
            self::fail('expected a NotFoundError');
        } catch (\Hushscript\Exception\NotFoundError $error) {
            self::assertSame(404, $error->status);
            self::assertSame(1000, $error->numericCode);
        }
    }

    public function testSendsExactlyOneRequestForASuccess(): void
    {
        $seen = [];
        $http = new FakeHttp([static function (RequestInterface $request) use (&$seen): \Psr\Http\Message\ResponseInterface {
            $seen[] = $request->getUri()->getPath();

            return FakeHttp::json(200, ['ok' => true]);
        }]);
        $rig = new Rig($http);

        $rig->api->request('GET', '/v1/account');

        self::assertSame(['/v1/account'], $seen);
        self::assertSame(1, $http->count());
    }

    public function testTheInternalCollaboratorIsNotReachableThroughTheLowLevelApi(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), (new \ReflectionClass(\Hushscript\Api::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);

        self::assertSame(['__construct', '__serialize', '__unserialize', 'request'], $methods, 'requestOrNull and the exchange are not part of the public surface');
        self::assertFalse(method_exists(\Hushscript\Api::class, 'requestOrNull'));
        $client = new \Hushscript\Client(\Hushscript\Tests\Support\Tokens::TEST);
        self::assertSame([], get_object_vars($client->api()), 'no property of the Api reaches the exchange from outside');
        self::assertStringContainsString('@internal', (string) (new \ReflectionClass(\Hushscript\Http\Exchange::class))->getDocComment());
    }
}
