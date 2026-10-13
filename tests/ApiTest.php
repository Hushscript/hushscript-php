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
            self::assertSame('The API answered '.$status.' with a body the SDK cannot read.', $error->getMessage());
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

        try {
            $rig->api->request('trace', '/v1/account');
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
            self::assertSame('Unsupported HTTP method "TRACE".', $error->getMessage());
        }
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

    /** @param \Closure(): \Throwable $make */
    #[\PHPUnit\Framework\Attributes\DataProviderExternal(RetryMiddlewareTest::class, 'unexpectedFailures')]
    public function testUnexpectedWrapperNamesTheOriginalTypeAndRedactsBothMessages(\Closure $make): void
    {
        $failure = $make();
        $summary = $failure::class.': boom hsr…[redacted]';
        $leads = [
            'The SDK failed before the request was sent. ' => false,
            'The SDK failed after the request was sent. ' => true,
            'The call failed with an error that is not a network failure; the request may have been sent. ' => null,
        ];
        foreach ($leads as $lead => $sent) {
            $error = \Hushscript\Http\Exchange::wrapUnexpected($failure, new \Hushscript\Credentials(\Hushscript\Tests\Support\Tokens::TEST), $sent);

            self::assertSame(\Hushscript\Exception\HushscriptException::class, $error::class);
            self::assertSame('unexpected_error', $error->errorCode);
            self::assertNull($error->status);
            self::assertNull($error->numericCode);
            self::assertSame($lead.$summary, $error->getMessage());
            $cause = $error->getPrevious();
            self::assertNotNull($cause);
            self::assertSame(\RuntimeException::class, $cause::class);
            self::assertSame($summary, $cause->getMessage());
            self::assertNull($cause->getPrevious());
            self::assertStringNotContainsString(\Hushscript\Tests\Support\Tokens::TEST, print_r(\Hushscript\Tests\Support\SdkFrames::only($error), true));
        }
    }

    /** A client without the hardened transport: what it throws may have followed the sending, so nothing claims either. */
    public function testAThrowFromAClientWithoutTheTransportSaysTheRequestMayHaveBeenSent(): void
    {
        $summary = 'TypeError: boom hsr…[redacted]';
        foreach (['/v1/account' => null, '/v1/agent/accounts/sg_1/claim' => 'The claim may have succeeded. Retrying will fail with agent_claim_invalid. If you have no token, contact support with the signup id.'] as $path => $hint) {
            $exchange = new \Hushscript\Http\Exchange(new FakeHttp([new \TypeError('boom '.\Hushscript\Tests\Support\Tokens::TEST)]), new \Hushscript\Credentials(\Hushscript\Tests\Support\Tokens::TEST), Rig::BASE);
            try {
                $exchange->request('POST', $path);
                self::fail('expected an SDK error');
            } catch (\Hushscript\Exception\HushscriptException $error) {
                if ($hint === null) {
                    self::assertSame(\Hushscript\Exception\HushscriptException::class, $error::class);
                    self::assertSame('unexpected_error', $error->errorCode);
                    self::assertSame('The call failed with an error that is not a network failure; the request may have been sent. '.$summary, $error->getMessage());
                } else {
                    self::assertSame(\Hushscript\Exception\OutcomeUnknownError::class, $error::class);
                    self::assertSame('outcome_unknown', $error->errorCode);
                    self::assertSame('The request may have been sent and no answer came back, so its outcome is unknown. '.$summary, $error->getMessage());
                }
                self::assertSame($hint, $error->hint);
                self::assertSame($summary, $error->getPrevious()?->getMessage());
            }
        }
    }

    public function testAnUnusableNeverRouteAnswerNamesTheOriginalFailure(): void
    {
        $broken = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $broken->method('getStatusCode')->willThrowException(new \TypeError('boom '.\Hushscript\Tests\Support\Tokens::TEST));
        $exchange = new \Hushscript\Http\Exchange(new FakeHttp([$broken]), new \Hushscript\Credentials(\Hushscript\Tests\Support\Tokens::TEST), Rig::BASE);

        try {
            $exchange->request('POST', '/v1/agent/accounts/sg_1/claim');
            self::fail('expected outcome_unknown');
        } catch (\Hushscript\Exception\HushscriptException $error) {
            self::assertSame(\Hushscript\Exception\OutcomeUnknownError::class, $error::class);
            self::assertSame('outcome_unknown', $error->errorCode);
            self::assertNull($error->status);
            self::assertNull($error->numericCode);
            self::assertSame('The answer could not be used, so its outcome is unknown. TypeError: boom hsr…[redacted]', $error->getMessage());
            $cause = $error->getPrevious();
            self::assertNotNull($cause);
            self::assertSame(\RuntimeException::class, $cause::class);
            self::assertSame('TypeError: boom hsr…[redacted]', $cause->getMessage());
            self::assertNull($cause->getPrevious());
            self::assertStringNotContainsString(\Hushscript\Tests\Support\Tokens::TEST, print_r(\Hushscript\Tests\Support\SdkFrames::only($error), true));
        }
    }

    public function testTheInternalCollaboratorIsNotReachableThroughTheLowLevelApi(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), (new \ReflectionClass(\Hushscript\Api::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);

        self::assertSame(['__construct', '__serialize', '__unserialize', 'request'], $methods, 'requestOrNull and the exchange are not part of the public surface');
        self::assertFalse((new \ReflectionClass(\Hushscript\Api::class))->hasMethod('requestOrNull'));
        $client = new \Hushscript\Client(\Hushscript\Tests\Support\Tokens::TEST);
        self::assertSame([], get_object_vars($client->api()), 'no property of the Api reaches the exchange from outside');
        self::assertStringContainsString('@internal', (string) (new \ReflectionClass(\Hushscript\Http\Exchange::class))->getDocComment());
    }

    public function testAFailureReadingAnAnswerFromAClientWithoutTheTransportSaysTheRequestWasSent(): void
    {
        $broken = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $broken->method('getStatusCode')->willReturn(200);
        $broken->method('getBody')->willThrowException(new \RuntimeException('read failed'));
        $exchange = new \Hushscript\Http\Exchange(new FakeHttp([$broken]), new \Hushscript\Credentials(\Hushscript\Tests\Support\Tokens::TEST), Rig::BASE);

        try {
            $exchange->request('GET', '/v1/account');
            self::fail('expected unexpected_error');
        } catch (\Hushscript\Exception\HushscriptException $error) {
            self::assertSame(\Hushscript\Exception\HushscriptException::class, $error::class);
            self::assertSame('unexpected_error', $error->errorCode);
            self::assertSame('The SDK failed after the request was sent. RuntimeException: read failed', $error->getMessage());
        }
    }

    public function testFetchOrNullGivesBackANullAnswerWithoutParsingIt(): void
    {
        $rig = new Rig(new FakeHttp([FakeHttp::json(200, 'null')]));

        $parsed = 0;
        $result = $rig->exchange->fetchOrNull('GET', '/v1/account', [], static function (\Hushscript\Http\Body $body) use (&$parsed): array {
            $parsed++;

            return $body->raw();
        });

        self::assertNull($result);
        self::assertSame(0, $parsed);
    }

    public function testFetchRefusesANullAnswerAsABadResponse(): void
    {
        $rig = new Rig(new FakeHttp([FakeHttp::json(203, 'null')]));

        try {
            $rig->exchange->fetch('GET', '/v1/account', [], null, static fn (\Hushscript\Http\Body $body): array => $body->raw());
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame('The API answered 203 with a body the SDK cannot read.', $error->getMessage());
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function fetchKinds(): iterable
    {
        yield 'fetch' => [false];
        yield 'fetchOrNull' => [true];
    }

    /** @param \Closure(\Hushscript\Http\Body, int): mixed $parse */
    private static function fetchWith(bool $orNull, Rig $rig, \Closure $parse): mixed
    {
        return $orNull
            ? $rig->exchange->fetchOrNull('GET', '/v1/account', [], $parse)
            : $rig->exchange->fetch('GET', '/v1/account', [], null, $parse);
    }

    #[DataProvider('fetchKinds')]
    public function testAnSdkErrorFromTheParserPassesThroughUnchanged(bool $orNull): void
    {
        $rig = new Rig(new FakeHttp([FakeHttp::json(200, ['a' => 1])]));
        $raised = new \Hushscript\Exception\HushscriptException('from the parser', 'unexpected_error');

        try {
            self::fetchWith($orNull, $rig, static fn (): never => throw $raised);
            self::fail('expected the parser error');
        } catch (\Hushscript\Exception\HushscriptException $error) {
            self::assertSame($raised, $error);
        }
    }

    #[DataProvider('fetchKinds')]
    public function testAnyOtherParserFailureIsABadResponseWithTheActualStatus(bool $orNull): void
    {
        $rig = new Rig(new FakeHttp([FakeHttp::json(203, ['a' => 1])]));

        try {
            self::fetchWith($orNull, $rig, static fn (): never => throw new \RuntimeException('parser bug'));
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(203, $error->status);
            self::assertSame('The API answered 203 with a body the SDK cannot read.', $error->getMessage());
        }
    }

    #[DataProvider('fetchKinds')]
    public function testAParserGetsTheBodyAndTheActualStatus(bool $orNull): void
    {
        $rig = new Rig(new FakeHttp([FakeHttp::json(201, ['a' => 1])]));

        self::assertSame([['a' => 1], 201], self::fetchWith($orNull, $rig, static fn (\Hushscript\Http\Body $body, int $status): array => [$body->raw(), $status]));
    }
}
