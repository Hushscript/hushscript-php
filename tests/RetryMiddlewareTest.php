<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\InsufficientBalanceError;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Exception\PermissionDeniedError;
use Hushscript\Exception\RateLimitError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\Rig;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class RetryMiddlewareTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    private static function connectFailure(int $errno = 7, float $pretransfer = 0.0): ConnectException
    {
        return new ConnectException('cURL error '.$errno, new Request('GET', Rig::BASE.'/v1/account'), null, ['errno' => $errno, 'pretransfer_time' => $pretransfer]);
    }

    private static function lostResponse(): RequestException
    {
        return new RequestException('cURL error 56: recv failure', new Request('GET', Rig::BASE.'/v1/account'), null, null, ['errno' => 56, 'pretransfer_time' => 0.01]);
    }

    /** @param array<string, mixed>|string $body */
    private static function reply(int $status, array|string $body = [], string $slug = '', ?string $retryAfter = null): Response
    {
        $headers = $retryAfter === null ? [] : ['Retry-After' => $retryAfter];

        return FakeHttp::json($status, $slug === '' ? $body : ['error' => $slug, 'code' => 1], $headers);
    }

    private static function ok(): Response
    {
        return FakeHttp::json(200, ['ok' => true]);
    }

    public function testASuccessfulGetIsSentOnceWithAuthAndUserAgent(): void
    {
        $http = new FakeHttp([self::ok()]);
        $rig = new Rig($http);

        self::assertSame(['ok' => true], $rig->api->request('GET', '/v1/account'));

        self::assertCount(1, $http->requests);
        self::assertSame('Bearer '.Tokens::TEST, $http->requests[0]->getHeaderLine('Authorization'));
        self::assertMatchesRegularExpression('~^hushscript-php/\d+\.\d+\.\d+ php/\d+\.\d+\.\d+~', $http->requests[0]->getHeaderLine('User-Agent'));
        self::assertSame([], $rig->clock->sleeps);
    }

    /** @return iterable<string, array{int}> */
    public static function retriedStatuses(): iterable
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('retriedStatuses')]
    public function testAGetIsRetriedOnTransientStatusesWithFullJitterBackoff(int $status): void
    {
        $http = new FakeHttp([self::reply($status), self::reply($status), self::reply($status), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('GET', '/v1/account');

        self::assertCount(4, $http->requests);
        self::assertSame([0.5, 1.0, 2.0], $rig->clock->sleeps);
    }

    public function testBackoffCeilingGrowsToEightSeconds(): void
    {
        $http = new FakeHttp(array_fill(0, 8, self::reply(503)));
        $rig = new Rig($http, maxRetries: 7);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('GET', '/v1/account');
        } finally {
            self::assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0, 8.0], $rig->clock->sleeps);
        }
    }

    public function testTheRetryBudgetIsMaxRetriesAndTheLastResponseBecomesTheError(): void
    {
        $http = new FakeHttp(array_fill(0, 4, self::reply(500, slug: 'internal_error')));
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('internal_error', $error->errorCode);
            self::assertSame(500, $error->status);
        }
        self::assertCount(4, $http->requests);
    }

    public function testMaxRetriesZeroSendsOnce(): void
    {
        $http = new FakeHttp([self::reply(503)]);
        $rig = new Rig($http, maxRetries: 0);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('GET', '/v1/account');
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function neverRetriedStatuses(): iterable
    {
        foreach ([400, 401, 402, 403, 404, 409, 422] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('neverRetriedStatuses')]
    public function testClientErrorsAreNeverRetried(int $status): void
    {
        $http = new FakeHttp([self::reply($status)]);
        $rig = new Rig($http);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('GET', '/v1/account');
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testRetryAfterOverridesBackoff(): void
    {
        $http = new FakeHttp([self::reply(429, retryAfter: '7'), self::reply(503, retryAfter: '60'), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('GET', '/v1/account');

        self::assertSame([7.0, 60.0], $rig->clock->sleeps);
    }

    /** @return iterable<string, array{string, int}> */
    public static function hugeRetryAfter(): iterable
    {
        yield '10 digits' => ['4000000000', 4_000_000_000];
        yield '400 digits' => [str_repeat('9', 400), 9007199254740991];
    }

    #[DataProvider('hugeRetryAfter')]
    public function testARetryAfterOfAnyLengthIsOverSixtyAndIsNotRetried(string $header, int $reported): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'rate_limited', retryAfter: $header), self::ok()]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected a RateLimitError');
        } catch (RateLimitError $error) {
            self::assertSame($reported, $error->retryAfter);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    public function testRetryAfterOver60On429RaisesRateLimitErrorAtOnce(): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'rate_limited', retryAfter: '61')]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected a RateLimitError');
        } catch (RateLimitError $error) {
            self::assertSame(61, $error->retryAfter);
            self::assertSame(429, $error->status);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    public function testRetryAfterOver60OnAnotherStatusRaisesThatStatusClassNotRateLimit(): void
    {
        $http = new FakeHttp([self::reply(503, slug: 'service_unavailable', retryAfter: '120')]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame(ApiError::class, $error::class);
            self::assertSame(120, $error->retryAfter);
            self::assertSame(503, $error->status);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    public function testNetworkErrorsAreRetriedOnGetAndEndAsNetworkError(): void
    {
        $http = new FakeHttp([self::lostResponse(), self::lostResponse(), self::lostResponse(), self::lostResponse()]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected a NetworkError');
        } catch (NetworkError $error) {
            self::assertSame('network_error', $error->errorCode);
            self::assertNull($error->status);
            self::assertStringContainsString('GET /v1/account', $error->getMessage());
        }
        self::assertCount(4, $http->requests);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function unexpectedFailures(): iterable
    {
        yield 'type error' => [new \TypeError('boom '.Tokens::TEST)];
        yield 'runtime error' => [new \RuntimeException('boom '.Tokens::TEST)];
        yield 'base PSR client error' => [new class ('boom '.Tokens::TEST) extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {}];
    }

    #[DataProvider('unexpectedFailures')]
    public function testAnyNonTransportThrowableStopsWithUnexpectedError(\Throwable $failure): void
    {
        foreach ([0, 3] as $maxRetries) {
            foreach ([['GET', '/v1/account'], ['POST', '/v1/agent/accounts/sg_1/claim'], ['POST', '/v1/agent/credentials/rotate'], ['POST', '/v1/agent/accounts'], ['POST', '/v1/org/members']] as [$method, $path]) {
                $http = new FakeHttp([$failure, self::ok()]);
                $rig = new Rig($http, maxRetries: $maxRetries);

                try {
                    $rig->api->request($method, $path);
                    self::fail('expected unexpected_error');
                } catch (HushscriptException $error) {
                    self::assertUnexpectedFailure($error, $failure::class.': boom hsr…[redacted]');
                }
                self::assertSame(1, $http->count());
                self::assertSame([], $rig->clock->sleeps);
            }
        }
    }

    private static function assertUnexpectedFailure(HushscriptException $error, string $summary): void
    {
        self::assertSame(HushscriptException::class, $error::class);
        self::assertSame('unexpected_error', $error->errorCode);
        self::assertNull($error->status);
        self::assertNull($error->numericCode);
        self::assertSame('The SDK failed after the request was sent. '.$summary, $error->getMessage());
        $cause = $error->getPrevious();
        self::assertNotNull($cause);
        self::assertSame(\RuntimeException::class, $cause::class);
        self::assertSame($summary, $cause->getMessage());
        self::assertNull($cause->getPrevious());
        self::assertStringNotContainsString(Tokens::TEST, print_r($error, true));
    }

    public function testAResponseBodyRuntimeFailureIsUnexpectedAndNeverRetried(): void
    {
        $broken = new Response(200, [], Utils::streamFor((static function (): \Generator {
            yield 'partial';
            throw new \RuntimeException('connection reset '.Tokens::TEST);
        })()));
        $http = new FakeHttp([$broken, self::ok()]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected unexpected_error');
        } catch (HushscriptException $error) {
            self::assertUnexpectedFailure($error, 'RuntimeException: connection reset hsr…[redacted]');
        }
        self::assertSame(1, $http->count());
        self::assertSame([], $rig->clock->sleeps);
    }

    public function testAResponseMetadataRuntimeFailureIsUnexpectedAndNeverRetried(): void
    {
        $broken = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $broken->method('getBody')->willReturn(Utils::streamFor('{}'));
        $broken->method('withBody')->willReturnSelf();
        $broken->method('getStatusCode')->willThrowException(new \RuntimeException('status '.Tokens::TEST));
        $http = new FakeHttp([$broken, self::ok()]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected unexpected_error');
        } catch (HushscriptException $error) {
            self::assertUnexpectedFailure($error, 'RuntimeException: status hsr…[redacted]');
        }
        self::assertSame(1, $http->count());
        self::assertSame([], $rig->clock->sleeps);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function transportFailures(): iterable
    {
        $request = new Request('GET', Rig::BASE.'/v1/account');
        yield 'PSR network' => [new ConnectException('boom '.Tokens::TEST, $request, null, ['errno' => 7])];
        yield 'PSR request' => [new RequestException('boom '.Tokens::TEST, $request)];
    }

    #[DataProvider('transportFailures')]
    public function testPsrTransportFailuresKeepRetriesAndTypedRedactedTerminalMessages(\Throwable $failure): void
    {
        $http = new FakeHttp([$failure, self::ok()]);
        $rig = new Rig($http);
        self::assertSame(['ok' => true], $rig->api->request('GET', '/v1/account'));
        self::assertSame(2, $http->count());
        self::assertSame([0.5], $rig->clock->sleeps);

        foreach ([0 => 1, 3 => 4] as $maxRetries => $attempts) {
            $http = new FakeHttp(array_fill(0, $attempts, $failure));
            $rig = new Rig($http, maxRetries: $maxRetries);
            try {
                $rig->api->request('GET', '/v1/account');
                self::fail('expected network_error');
            } catch (HushscriptException $error) {
                $summary = $failure::class.': boom hsr…[redacted]';
                self::assertSame(NetworkError::class, $error::class);
                self::assertSame('network_error', $error->errorCode);
                self::assertNull($error->status);
                self::assertNull($error->numericCode);
                self::assertSame('Could not reach the Hushscript API: GET /v1/account. '.$summary, $error->getMessage());
                $cause = $error->getPrevious();
                self::assertNotNull($cause);
                self::assertSame(\RuntimeException::class, $cause::class);
                self::assertSame($summary, $cause->getMessage());
                self::assertNull($cause->getPrevious());
                self::assertStringNotContainsString(Tokens::TEST, print_r($error, true));
            }
            self::assertSame($attempts, $http->count());
        }
    }

    // Purchase (spec 7.2): network error, 502, 503; at most one retry, same body.

    public function testPurchaseRetriesOnce502WithTheSameKey(): void
    {
        $http = new FakeHttp([self::reply(502, 'bad gateway'), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '300min', 'idempotency_key' => self::KEY]);

        self::assertCount(2, $http->requests);
        self::assertSame((string) $http->requests[0]->getBody(), (string) $http->requests[1]->getBody());
        self::assertStringContainsString(self::KEY, (string) $http->requests[1]->getBody());
    }

    public function testPurchaseIsCappedAtOneRetryWhateverMaxRetriesSays(): void
    {
        $http = new FakeHttp([self::reply(503), self::reply(503), self::ok()]);
        $rig = new Rig($http, maxRetries: 10);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '300min', 'idempotency_key' => self::KEY]);
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testPurchaseIsNeverRetriedOnA4xxOr500(): void
    {
        foreach ([[403, 'pat_purchase_requires_app'], [500, 'internal_error'], [429, 'rate_limited'], [504, 'timeout']] as [$status, $slug]) {
            $http = new FakeHttp([self::reply($status, slug: $slug), self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '300min', 'idempotency_key' => self::KEY]);
                self::fail('expected an error');
            } catch (HushscriptException $error) {
                self::assertSame($slug, $error->errorCode);
            }
            self::assertCount(1, $http->requests, "status {$status}");
        }
    }

    public function testPurchaseRetriesOnceOnANetworkError(): void
    {
        $http = new FakeHttp([self::lostResponse(), self::lostResponse(), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(NetworkError::class);
        try {
            $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '300min', 'idempotency_key' => self::KEY]);
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testSetupSessionFollowsThePurchaseRule(): void
    {
        $http = new FakeHttp([self::reply(502), self::reply(502), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('POST', '/v1/billing/setup-session');
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testPurchaseIsClassifiedFromTheRealPathWhateverTheQueryOrSlash(): void
    {
        $http = new FakeHttp([self::reply(502), self::reply(502), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('POST', '/v1/billing/purchase/', ['x' => '1'], ['pack' => '45min']);
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    // Claim, rotate and signup: never replayed; a lost response is an unknown outcome.

    /** @return iterable<string, array{string, string|null}> */
    public static function neverRoutes(): iterable
    {
        yield 'claim' => ['/v1/agent/accounts/sg_1/claim', 'The claim may have succeeded. Retrying will fail with agent_claim_invalid. If you have no token, contact support with the signup id.'];
        yield 'rotate' => ['/v1/agent/credentials/rotate', 'The old token may already be revoked. Try a request with it; if it fails, the new token was lost and the account needs support.'];
        yield 'signup' => ['/v1/agent/accounts', null];
    }

    #[DataProvider('neverRoutes')]
    public function testALostResponseIsAnOutcomeUnknownErrorWithItsHintAndIsSentOnce(string $path, ?string $hint): void
    {
        $http = new FakeHttp([self::lostResponse(), self::ok()]);
        $rig = new Rig($http);

        try {
            $rig->api->request('POST', $path, body: []);
            self::fail('expected an OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertNull($error->status);
            self::assertNull($error->numericCode);
            self::assertSame('No response arrived for POST '.$path.'. GuzzleHttp\\Exception\\RequestException: cURL error 56: recv failure', $error->getMessage());
            $cause = $error->getPrevious();
            self::assertNotNull($cause);
            self::assertSame(\RuntimeException::class, $cause::class);
            self::assertSame('GuzzleHttp\\Exception\\RequestException: cURL error 56: recv failure', $cause->getMessage());
            self::assertNull($cause->getPrevious());
            self::assertSame($hint, $error->hint);
            self::assertSame('outcome_unknown', $error->errorCode);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    #[DataProvider('neverRoutes')]
    public function testATimeoutAfterTheBodyWasWrittenIsAnUnknownOutcome(string $path, ?string $hint): void
    {
        $http = new FakeHttp([self::connectFailure(28, 0.02), self::ok()]);
        $rig = new Rig($http);
        try {
            $rig->api->request('POST', $path, body: []);
            self::fail('expected OutcomeUnknownError');
        } catch (HushscriptException $error) {
            self::assertSame(OutcomeUnknownError::class, $error::class);
            self::assertSame('outcome_unknown', $error->errorCode);
            self::assertNull($error->status);
            self::assertSame($hint, $error->hint);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    #[DataProvider('neverRoutes')]
    public function testAFailureBeforeTheRequestWasSentIsAPlainNetworkErrorAndStillNotReplayed(string $path, ?string $hint): void
    {
        $http = new FakeHttp([self::connectFailure(7), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(NetworkError::class);
        try {
            $rig->api->request('POST', $path, body: []);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    #[DataProvider('neverRoutes')]
    public function testAServerErrorOnANeverRouteIsNotRetried(string $path, ?string $hint): void
    {
        $http = new FakeHttp([self::reply(503), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('POST', $path, body: []);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    // Mutations that are not listed: retried only when the request provably never left.

    #[DataProvider('mutationRoutes')]
    public function testAnUnlistedMutationIsRetriedOnlyWhenNotSent(string $method, string $path): void
    {
        $http = new FakeHttp([self::connectFailure(6), self::connectFailure(7), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request($method, $path, body: ['x' => 1]);

        self::assertCount(3, $http->requests);
    }

    #[DataProvider('mutationRoutes')]
    public function testAnUnlistedMutationIsNotReplayedOnceSent(string $method, string $path): void
    {
        foreach ([self::lostResponse()] as $failure) {
            $http = new FakeHttp([$failure, self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request($method, $path, body: ['x' => 1]);
                self::fail('expected a NetworkError');
            } catch (NetworkError) {
                self::assertCount(1, $http->requests);
            }
        }
    }

    #[DataProvider('mutationRoutes')]
    public function testAnUnlistedMutationIsNotRetriedOnAnyStatus(string $method, string $path): void
    {
        foreach ([500, 502, 503, 429] as $status) {
            $http = new FakeHttp([self::reply($status), self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request($method, $path, body: ['x' => 1]);
                self::fail('expected an ApiError');
            } catch (ApiError) {
                self::assertCount(1, $http->requests, "status {$status}");
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function mutationRoutes(): iterable
    {
        yield 'org member add' => ['POST', '/v1/org/members'];
        yield 'org delete' => ['DELETE', '/v1/org/members/m1'];
        yield 'default card' => ['PUT', '/v1/billing/cards/default'];
        yield 'patch' => ['PATCH', '/v1/org/settings'];
    }

    public function testNotSentWithoutAConnectErrnoMeansSent(): void
    {
        // errno 28 with a started transfer, errno 52 (empty reply), and an unrelated errno: all after the write.
        foreach ([self::connectFailure(28, 0.5), self::connectFailure(52, 0.5), self::lostResponse()] as $failure) {
            $http = new FakeHttp([$failure, self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request('POST', '/v1/org/members', body: ['x' => 1]);
                self::fail('expected a NetworkError');
            } catch (NetworkError) {
                self::assertCount(1, $http->requests);
            }
        }
    }

    /** @return iterable<string, array{int}> */
    public static function setupErrnos(): iterable
    {
        foreach ([5 => 'proxy dns', 6 => 'dns', 7 => 'connect', 35 => 'tls', 51 => 'peer cert', 58 => 'cert problem', 59 => 'cipher', 60 => 'cacert', 77 => 'cacert file', 83 => 'issuer'] as $errno => $name) {
            yield $name => [$errno];
        }
    }

    #[DataProvider('setupErrnos')]
    public function testConnectionSetupErrnosCountAsNotSent(int $errno): void
    {
        // A plain RequestException (not a ConnectException) carries errno 60 and friends in Guzzle.
        $failure = new RequestException('setup', new Request('POST', Rig::BASE.'/v1/org/members'), null, null, ['errno' => $errno]);
        $http = new FakeHttp([$failure, self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/org/members', body: ['x' => 1]);

        self::assertCount(2, $http->requests);
    }

    public function testATimeoutBeforeTheTransferBeganCountsAsNotSent(): void
    {
        $http = new FakeHttp([self::connectFailure(28, 0.0), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/org/members', body: ['x' => 1]);

        self::assertCount(2, $http->requests);
    }

    // A response body that cannot be rewound still reaches the caller

    public function testASuccessBodyOnANonSeekableStreamReachesTheCaller(): void
    {
        $http = new FakeHttp([new Response(200, [], new NoSeekStream(Utils::streamFor('{"ok":true}')))]);
        $rig = new Rig($http);

        self::assertSame(['ok' => true], $rig->api->request('GET', '/v1/account'));
    }

    public function testAnErrorBodyOnANonSeekableStreamKeepsItsCodeAndHint(): void
    {
        $body = json_encode(['error' => 'insufficient_balance', 'code' => 3001, 'needed_seconds' => 1250, 'balance_seconds' => 600], JSON_THROW_ON_ERROR);
        $http = new FakeHttp([new Response(402, [], new NoSeekStream(Utils::streamFor($body)))]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an error');
        } catch (HushscriptException $error) {
            self::assertSame('insufficient_balance', $error->errorCode);
            self::assertSame(3001, $error->numericCode);
            self::assertSame(InsufficientBalanceError::class, $error::class);
            self::assertSame(402, $error->status);
            self::assertSame('Not enough minutes: 21 needed, 10 available. Buy a pack with the SDK\'s buy method or in the app.', $error->hint);
        }
    }

    public function testACreate429SlugIsReadFromANonSeekableStream(): void
    {
        $limited = new Response(429, [], new NoSeekStream(Utils::streamFor('{"error":"too_many_active_jobs","code":1}')));
        $http = new FakeHttp([$limited, self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['size_bytes' => 1, 'idempotency_key' => self::KEY]);

        self::assertSame([30.0], $rig->clock->sleeps, 'the active-jobs schedule waits 30 s; the rate-limit schedule would wait 15 s');
    }

    // Upload create (spec 7.2, 7.5)

    public function testAKeyedUploadCreateIsRetriedLikeAnyCreate(): void
    {
        $http = new FakeHttp([self::reply(500), self::lostResponse(), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['size_bytes' => 1, 'idempotency_key' => self::KEY]);

        self::assertCount(3, $http->requests);
    }

    public function testAnUnkeyedUploadCreateIsAnyOtherMutation(): void
    {
        foreach ([['size_bytes' => 1], ['size_bytes' => 1, 'idempotency_key' => ''], ['size_bytes' => 1, 'idempotency_key' => 5]] as $body) {
            $http = new FakeHttp([self::reply(500), self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request('POST', '/v1/uploads', body: $body);
                self::fail('expected an ApiError');
            } catch (ApiError) {
                self::assertCount(1, $http->requests);
            }
            $lost = new FakeHttp([self::lostResponse(), self::ok()]);
            try {
                (new Rig($lost))->api->request('POST', '/v1/uploads', body: $body);
                self::fail('expected a NetworkError');
            } catch (NetworkError) {
                self::assertCount(1, $lost->requests);
            }
        }
    }

    public function testACreateWithRateLimitedAndNoRetryAfterBacksOff15Then30Then60(): void
    {
        $http = new FakeHttp(array_fill(0, 4, self::reply(429, slug: 'rate_limited')));
        $rig = new Rig($http);

        $this->expectException(RateLimitError::class);
        try {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
        } finally {
            self::assertCount(4, $http->requests);
            self::assertSame([15.0, 30.0, 60.0], $rig->clock->sleeps);
        }
    }

    public function testACreateRateLimitWithRetryAfterWaitsThatLong(): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'rate_limited', retryAfter: '5'), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame([5.0], $rig->clock->sleeps);
    }

    public function testTheRateLimitScheduleCountsOnlyRateLimitResponses(): void
    {
        // Two 500s used two retries; the first 429 still gets the first entry (15 s), not the third.
        $http = new FakeHttp([self::reply(500), self::reply(500), self::reply(429, slug: 'rate_limited'), self::ok()]);
        $rig = new Rig($http, maxRetries: 10);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame([0.5, 1.0, 15.0], $rig->clock->sleeps);
    }

    public function testTooManyActiveJobsWaits30SecondsForTenTriesThenRaises(): void
    {
        $http = new FakeHttp(array_fill(0, 12, self::reply(429, slug: 'too_many_active_jobs')));
        $rig = new Rig($http, maxRetries: 3);

        try {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
            self::fail('expected a RateLimitError');
        } catch (RateLimitError $error) {
            self::assertSame('too_many_active_jobs', $error->errorCode);
        }
        self::assertCount(10, $http->requests);
        self::assertSame(array_fill(0, 9, 30.0), $rig->clock->sleeps);
    }

    public function testTooManyActiveJobsIsDisabledByMaxRetriesZeroOnly(): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'too_many_active_jobs'), self::ok()]);
        $rig = new Rig($http, maxRetries: 0);

        $this->expectException(RateLimitError::class);
        try {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testTooManyActiveJobsSucceedsAfterAWait(): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'too_many_active_jobs'), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame([30.0], $rig->clock->sleeps);
    }

    public function testCreatesArePacedFourPerMinutePerAttempt(): void
    {
        $http = new FakeHttp([self::ok(), self::ok(), self::ok(), self::ok(), self::ok()]);
        $rig = new Rig($http);

        for ($i = 0; $i < 4; $i++) {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
        }
        self::assertSame([], $rig->clock->sleeps);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame([60.0], $rig->clock->sleeps);
    }

    public function testARetriedCreateTakesAnotherPacingToken(): void
    {
        $http = new FakeHttp([self::reply(500), self::ok(), self::reply(500), self::ok(), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]); // tokens 1, 2
        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]); // tokens 3, 4
        self::assertSame([0.5, 0.5], $rig->clock->sleeps);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]); // token 5 waits

        self::assertCount(3, $rig->clock->slept());
        self::assertEqualsWithDelta(59.0, $rig->clock->slept()[2], 0.001);
    }

    public function testUnkeyedCreatesAndOtherPathsAreHandledByPathNotByKey(): void
    {
        $http = new FakeHttp(array_fill(0, 7, self::ok()));
        $rig = new Rig($http);

        for ($i = 0; $i < 5; $i++) {
            $rig->api->request('POST', '/v1/uploads', body: ['size_bytes' => 1]);
        }
        self::assertCount(1, $rig->clock->sleeps, 'an unkeyed create is still a create and still paced');

        $before = count($rig->clock->slept());
        $rig->api->request('GET', '/v1/uploads');
        $rig->api->request('POST', '/v1/uploads/j1/heartbeat', body: []);
        self::assertCount($before, $rig->clock->slept(), 'only POST /v1/uploads is paced');
    }

    // Complete, parts, heartbeat

    public function testCompleteAndHeartbeatAndPartsAreRetriedOnServerErrors(): void
    {
        foreach ([['POST', '/v1/uploads/j1/complete', 500], ['POST', '/v1/uploads/j1/heartbeat', 500], ['POST', '/v1/uploads/j1/heartbeat', 503], ['PUT', '/v1/uploads/j1/parts/2', 500], ['POST', '/v1/uploads/j1/complete', 429]] as [$method, $path, $status]) {
            $http = new FakeHttp([self::reply($status), self::ok()]);
            $rig = new Rig($http);

            $rig->api->request($method, $path, body: []);

            self::assertCount(2, $http->requests, "{$method} {$path}");
        }
    }

    public function testHeartbeatIsNotRetriedOn501(): void
    {
        $http = new FakeHttp([self::reply(501), self::ok()]);
        $rig = new Rig($http);
        try {
            $rig->api->request('POST', '/v1/uploads/j1/heartbeat', body: []);
            self::fail('expected ApiError');
        } catch (ApiError $error) {
            self::assertSame(ApiError::class, $error::class);
            self::assertSame('http_501', $error->errorCode);
            self::assertSame(501, $error->status);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    public function testCompleteRetriesAnyFiveHundred(): void
    {
        $http = new FakeHttp([self::reply(501), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads/j1/complete', body: []);

        self::assertCount(2, $http->requests);
    }

    // Authorization

    public function testAuthorizationIsReadPerAttemptAfterTheSleep(): void
    {
        $http = new FakeHttp([self::reply(503), self::ok()]);
        $rig = new Rig($http);
        $rig->clock->onSleep = function () use ($rig): void {
            $rig->credentials->replace(Tokens::ROTATED);
        };

        $rig->api->request('GET', '/v1/account');

        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::ROTATED], $http->authorizations);
    }

    public function testAuthorizationIsReadAfterThePacingSleepToo(): void
    {
        $http = new FakeHttp(array_fill(0, 5, self::ok()));
        $rig = new Rig($http);
        for ($i = 0; $i < 4; $i++) {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
        }
        $rig->clock->onSleep = function () use ($rig): void {
            $rig->credentials->replace(Tokens::ROTATED);
        };

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame('Bearer '.Tokens::ROTATED, $http->authorizations[4]);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignOrigins(): iterable
    {
        yield 'other host' => ['http://evil.example:4010/v1/account'];
        yield 'other port' => ['http://127.0.0.1:4011/v1/account'];
        yield 'other scheme' => ['https://127.0.0.1:4010/v1/account'];
        yield 'userinfo trick' => ['http://127.0.0.1.evil.example/v1/account'];
    }

    #[DataProvider('foreignOrigins')]
    public function testAuthorizationGoesOnlyToTheConfiguredOrigin(string $url): void
    {
        $http = new FakeHttp([self::ok()]);
        $rig = new Rig($http);

        $rig->transport->sendRequest(new Request('GET', $url, ['Authorization' => 'Bearer caller-supplied']));

        self::assertSame([''], $http->authorizations);
    }

    public function testAForeignRequestMatchesNoApiRoute(): void
    {
        // A signed URL on another host that looks like the claim path is still a plain mutation there.
        $http = new FakeHttp([self::lostResponse()]);
        $rig = new Rig($http);

        $this->expectException(NetworkError::class);
        $rig->transport->sendRequest(new Request('POST', 'http://other.example/v1/agent/credentials/rotate'));
    }

    public function testARedirectIsReturnedNotFollowedAndBecomesAnApiError(): void
    {
        $http = new FakeHttp([new Response(302, ['Location' => 'https://evil.example/'])]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('http_302', $error->errorCode);
            self::assertSame(302, $error->status);
        }
        self::assertCount(1, $http->requests);
    }

    public function testAnAuthenticationErrorIsNotRetried(): void
    {
        $http = new FakeHttp([self::reply(401, slug: 'unauthorized'), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(AuthenticationError::class);
        try {
            $rig->api->request('GET', '/v1/account');
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testPermissionErrorsCarryTheScopeHint(): void
    {
        $http = new FakeHttp([self::reply(403, slug: 'pat_scope_missing')]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected a PermissionDeniedError');
        } catch (PermissionDeniedError $error) {
            self::assertStringContainsString('"account:read" permission', (string) $error->hint);
        }
    }

    // Bodies

    public function testABodyThatCannotBeRewoundIsNeverRetried(): void
    {
        $http = new FakeHttp([self::reply(503), self::ok()]);
        $rig = new Rig($http);
        $stream = Utils::streamFor((static function (): \Generator {
            yield '{"x":1}';
        })());
        $request = (new Request('POST', Rig::BASE.'/v1/billing/purchase'))->withBody($stream);

        $response = $rig->transport->sendRequest($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $http->requests);
    }

    public function testEachAttemptSendsTheWholeBody(): void
    {
        $bodies = [];
        $capture = static function (RequestInterface $request) use (&$bodies): Response {
            $bodies[] = $request->getBody()->getContents();

            return count($bodies) === 1 ? self::reply(503) : self::ok();
        };
        $http = new FakeHttp([$capture, $capture]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '45min', 'idempotency_key' => self::KEY]);

        self::assertSame($bodies[0], $bodies[1]);
        self::assertNotSame('', $bodies[1]);
    }

    // Logging

    public function testOneLogLinePerAttemptWithTheRetryDelay(): void
    {
        $http = new FakeHttp([self::reply(503), self::lostResponse(), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('GET', '/v1/account');

        self::assertSame(
            [
                'hushscript GET /v1/account 503 attempt=1 0ms retry_in=0.5s',
                'hushscript GET /v1/account error attempt=2 0ms retry_in=1s',
                'hushscript GET /v1/account 200 attempt=3 0ms',
            ],
            $rig->logLines,
        );
    }

    public function testAPutToASignedStorageUrlIsAPartUploadAndIsRetried(): void
    {
        $http = new FakeHttp([self::reply(503), self::reply(200)]);
        $rig = new Rig($http);

        $response = $rig->transport->sendRequest(new Request('PUT', 'https://r2.example/bucket/obj?X-Amz-Signature=secret', [], 'bytes'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $http->requests);
        self::assertSame(['', ''], $http->authorizations, 'storage never sees the token');
    }

    public function testAPutToASignedStorageUrlIsRetriedAfterALostConnection(): void
    {
        $http = new FakeHttp([self::lostResponse(), self::reply(200)]);
        $rig = new Rig($http);

        $rig->transport->sendRequest(new Request('PUT', 'https://r2.example/bucket/obj?X-Amz-Signature=secret', [], 'bytes'));

        self::assertCount(2, $http->requests);
    }

    public function testAnotherMethodOnAForeignHostIsStillAnUnclassifiedMutation(): void
    {
        $http = new FakeHttp([self::reply(503)]);
        $rig = new Rig($http);

        $response = $rig->transport->sendRequest(new Request('POST', 'https://r2.example/bucket/obj', [], 'bytes'));

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $http->requests);
    }

    public function testForeignRequestsAreLoggedWithoutTheirUrl(): void
    {
        $http = new FakeHttp([self::ok()]);
        $rig = new Rig($http);

        $rig->transport->sendRequest(new Request('PUT', 'https://r2.example/bucket/obj?X-Amz-Signature=secret'));

        self::assertSame(['hushscript PUT external 200 attempt=1 0ms'], $rig->logLines);
    }

    public function testNothingIsLoggedWhenLoggingIsOff(): void
    {
        $http = new FakeHttp([self::ok()]);
        $rig = new Rig($http, logLevel: 'off');

        $rig->api->request('GET', '/v1/account');

        self::assertSame([], $rig->logLines);
    }

    public function testTheDefaultJitterSamplesTheWholeRangeInsideTheCeiling(): void
    {
        $samples = [];
        for ($index = 0; $index < 128; $index++) {
            $clock = new FakeClock();
            $http = new FakeHttp([self::reply(503), self::ok()]);
            $credentials = new \Hushscript\Credentials(Tokens::TEST);
            $transport = new \Hushscript\Http\RetryMiddleware($http, $credentials, Rig::BASE, $clock, new \Hushscript\Log(), 3);
            $transport->sendRequest(new Request('GET', Rig::BASE.'/v1/account'));
            self::assertCount(1, $clock->sleeps);
            self::assertCount(2, $http->requests);
            $samples[] = $clock->sleeps[0];
        }
        self::assertNotEmpty($samples);
        self::assertGreaterThanOrEqual(0.0, min($samples));
        self::assertLessThanOrEqual(0.5, max($samples));
        self::assertLessThan(0.125, min($samples));
        self::assertGreaterThan(0.375, max($samples));
        self::assertGreaterThan(1, count(array_unique($samples)));
    }
}
