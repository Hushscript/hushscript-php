<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Credentials;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\ConflictError;
use Hushscript\Exception\ErrorMapper;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\InsufficientBalanceError;
use Hushscript\Exception\NotFoundError;
use Hushscript\Exception\PermissionDeniedError;
use Hushscript\Exception\RateLimitError;
use Hushscript\Exception\UploadError;
use Hushscript\Exception\ValidationError;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    /**
     * @param  array<string, mixed>|string  $body
     * @param  array<string, string>  $headers
     */
    private static function map(int $status, array|string $body, string $method = 'GET', string $path = '/v1/account', array $headers = []): HushscriptException
    {
        return ErrorMapper::fromResponse(FakeHttp::json($status, $body, $headers), new Credentials(Tokens::TEST), $method, $path);
    }

    /** @return iterable<string, array{string, int, int, class-string<HushscriptException>}> */
    public static function slugs(): iterable
    {
        yield 'unauthorized' => ['unauthorized', 2000, 401, AuthenticationError::class];
        yield 'agent_claim_invalid' => ['agent_claim_invalid', 4036, 401, AuthenticationError::class];
        yield 'pat_scope_missing' => ['pat_scope_missing', 2023, 403, PermissionDeniedError::class];
        yield 'pat_forbidden' => ['pat_forbidden', 2022, 403, PermissionDeniedError::class];
        yield 'account_suspended' => ['account_suspended', 2013, 403, PermissionDeniedError::class];
        yield 'agent_pack_locked' => ['agent_pack_locked', 4037, 403, PermissionDeniedError::class];
        yield 'pat_purchase_requires_app' => ['pat_purchase_requires_app', 4021, 403, PermissionDeniedError::class];
        yield 'pat_purchase_requires_authentication' => ['pat_purchase_requires_authentication', 4023, 403, PermissionDeniedError::class];
        yield 'country_restricted' => ['country_restricted', 2011, 403, PermissionDeniedError::class];
        yield 'signup_not_allowed' => ['signup_not_allowed', 2012, 403, PermissionDeniedError::class];
        yield 'insufficient_balance' => ['insufficient_balance', 3001, 402, InsufficientBalanceError::class];
        yield 'org_insufficient_balance' => ['org_insufficient_balance', 3003, 402, InsufficientBalanceError::class];
        yield 'org_pool_expired' => ['org_pool_expired', 3004, 402, InsufficientBalanceError::class];
        yield 'org_plan_frozen' => ['org_plan_frozen', 3005, 402, InsufficientBalanceError::class];
        yield 'wrong_state' => ['wrong_state', 4001, 409, ConflictError::class];
        yield 'agent_signup_pending' => ['agent_signup_pending', 4035, 409, ConflictError::class];
        yield 'pat_limit' => ['pat_limit', 4022, 409, ConflictError::class];
        yield 'rate_limited' => ['rate_limited', 5000, 429, RateLimitError::class];
        yield 'too_many_active_jobs' => ['too_many_active_jobs', 5001, 429, RateLimitError::class];
        yield 'agent_purchase_capped' => ['agent_purchase_capped', 4038, 429, RateLimitError::class];
        yield 'url_target_busy' => ['url_target_busy', 1042, 429, RateLimitError::class];
        yield 'upload_incomplete' => ['upload_incomplete', 1041, 409, UploadError::class];
        yield 'size_exceeded' => ['size_exceeded', 1024, 413, UploadError::class];
    }

    /** @param class-string<HushscriptException> $class */
    #[DataProvider('slugs')]
    public function testEverySlugOfSpec92MapsToItsClass(string $slug, int $code, int $status, string $class): void
    {
        $error = self::map($status, ['error' => $slug, 'code' => $code]);

        self::assertInstanceOf($class, $error);
        self::assertSame($slug, $error->errorCode);
        self::assertSame($code, $error->numericCode);
        self::assertSame($status, $error->status);
    }

    /** @return iterable<string, array{int, class-string<HushscriptException>}> */
    public static function statuses(): iterable
    {
        yield '400' => [400, ValidationError::class];
        yield '401' => [401, AuthenticationError::class];
        yield '402' => [402, InsufficientBalanceError::class];
        yield '403' => [403, PermissionDeniedError::class];
        yield '404' => [404, NotFoundError::class];
        yield '409' => [409, ConflictError::class];
        yield '422' => [422, ValidationError::class];
        yield '429' => [429, RateLimitError::class];
        yield '500' => [500, ApiError::class];
        yield '302' => [302, ApiError::class];
    }

    /** @param class-string<HushscriptException> $class */
    #[DataProvider('statuses')]
    public function testAnUnlistedSlugFallsBackToItsStatusClass(int $status, string $class): void
    {
        $error = self::map($status, ['error' => 'something_new', 'code' => 9999]);

        self::assertSame($class, $error::class);
        self::assertSame('something_new', $error->errorCode);
    }

    public function testABodyWithoutASlugIsNamedAfterItsStatus(): void
    {
        $error = self::map(502, 'Bad gateway');

        self::assertSame(ApiError::class, $error::class);
        self::assertSame('http_502', $error->errorCode);
        self::assertNull($error->numericCode);
        self::assertSame(502, $error->status);
        self::assertSame('The API answered 502 (http_502).', $error->getMessage());
    }

    public function testTheMessageIsTheCatalogMeaning(): void
    {
        $error = self::map(402, ['error' => 'insufficient_balance', 'code' => 3001]);

        self::assertNotSame('', $error->getMessage());
        self::assertStringNotContainsString('The API answered', $error->getMessage());
    }

    public function testExtraKeysAndNestedDetailsBecomeSnakeCaseDetails(): void
    {
        $error = self::map(402, ['error' => 'insufficient_balance', 'code' => 3001, 'needed_seconds' => 1250, 'balanceSeconds' => 600, 'details' => ['heldSeconds' => 5, 'needed_seconds' => 1, 'deep' => ['someKey' => [['innerKey' => 1]]]]]);

        self::assertSame(
            ['needed_seconds' => 1250, 'balance_seconds' => 600, 'held_seconds' => 5, 'deep' => ['some_key' => [['inner_key' => 1]]]],
            $error->details,
        );
        self::assertSame('Not enough minutes: 21 needed, 10 available. Buy a pack with the SDK\'s buy method or in the app.', $error->hint);
    }

    public function testANonObjectDetailsValueIsKept(): void
    {
        $error = self::map(400, ['error' => 'bad_request', 'code' => 1, 'details' => 'oops']);

        self::assertSame(['details' => 'oops'], $error->details);
    }

    public function testTheScopeHintNamesTheOperationsScope(): void
    {
        $error = self::map(403, ['error' => 'pat_scope_missing', 'code' => 2023], 'GET', '/v1/transcripts');

        self::assertNotNull($error->hint);
        self::assertStringContainsString('"transcripts:read" permission', $error->hint);
    }

    public function testAnUnknownOperationLeavesTheScopeHintSentenceOut(): void
    {
        $error = self::map(403, ['error' => 'pat_scope_missing', 'code' => 2023], 'GET', '/v1/nope');

        self::assertNotNull($error->hint);
        self::assertStringNotContainsString('This call needs', $error->hint);
    }

    public function testWrongStateHasItsHintOnlyOnJobAndUploadOperations(): void
    {
        $body = ['error' => 'wrong_state', 'code' => 4001];

        self::assertNotNull(self::map(409, $body, 'GET', '/v1/jobs/j1')->hint);
        self::assertNotNull(self::map(409, $body, 'POST', '/v1/uploads/j1/complete')->hint);
        self::assertNotNull(self::map(409, $body, 'POST', '/v1/uploads')->hint);
        self::assertNull(self::map(409, $body, 'POST', '/v1/agent/accounts/sg_1/claim')->hint);
        self::assertNull(self::map(409, $body, 'GET', '/v1/jobsfoo')->hint);
    }

    public function testRetryAfterComesFromTheHeaderThenFromTheDetails(): void
    {
        $fromHeader = self::map(429, ['error' => 'rate_limited', 'code' => 5000], headers: ['Retry-After' => '61']);
        $fromDetails = self::map(429, ['error' => 'agent_purchase_capped', 'code' => 4038, 'retry_after_seconds' => 3600]);
        $none = self::map(429, ['error' => 'rate_limited', 'code' => 5000]);

        self::assertSame(61, $fromHeader->retryAfter);
        self::assertStringContainsString('in 61 s.', (string) $fromHeader->hint);
        self::assertSame(3600, $fromDetails->retryAfter);
        self::assertNull($none->retryAfter);
        self::assertSame('Too many requests.', $none->hint);
    }

    /** @return iterable<string, array{string|null, int|null}> */
    public static function retryAfterValues(): iterable
    {
        yield 'seconds' => ['30', 30];
        yield 'padded' => [' 7 ', 7];
        yield 'zero' => ['0', 0];
        yield 'date' => ['Wed, 21 Oct 2026 07:28:00 GMT', null];
        yield 'negative' => ['-1', null];
        yield 'decimal' => ['1.5', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'huge' => ['99999999999', 99999999999];
        yield 'leading zeros' => ['0000000000000000000000000000000000007', 7];
        yield 'at the cap' => ['9007199254740991', 9007199254740991];
        yield 'above the cap' => ['9007199254740992', 9007199254740991];
        yield '400 digits' => [str_repeat('9', 400), 9007199254740991];
        yield 'plus sign' => ['+5', null];
    }

    #[DataProvider('retryAfterValues')]
    public function testRetryAfterIsWholeSecondsOnly(?string $header, ?int $expected): void
    {
        self::assertSame($expected, ErrorMapper::parseRetryAfter($header));
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function retryAfterDetails(): iterable
    {
        yield 'seconds' => ['3600', 3600];
        yield 'zero' => ['0', 0];
        yield 'beyond int64' => ['99999999999999999999', 9007199254740991];
        yield '400 digits' => [str_repeat('9', 400), 9007199254740991];
        yield 'negative' => ['-1', null];
        yield 'fraction' => ['1.5', null];
        yield 'string' => ['"60"', null];
        yield 'null' => ['null', null];
    }

    #[DataProvider('retryAfterDetails')]
    public function testTheRetryAfterSecondsDetailIsAnIntegerOfAnyLength(string $json, ?int $expected): void
    {
        $nested = self::map(429, '{"error":"agent_purchase_capped","code":4038,"details":{"retry_after_seconds":'.$json.'}}');
        $top = self::map(429, '{"error":"agent_purchase_capped","code":4038,"retry_after_seconds":'.$json.'}');

        self::assertSame($expected, $nested->retryAfter);
        self::assertSame($expected, $top->retryAfter);
    }

    public function testTheTokenIsScrubbedFromEveryField(): void
    {
        $error = self::map(401, ['error' => 'unauthorized', 'code' => 2000, 'echo' => Tokens::TEST, Tokens::TEST => 'k']);

        self::assertSame(['echo' => 'hsr…[redacted]', 'hsr…[redacted]' => 'k'], $error->details);
    }

    public function testTheSlugItselfIsScrubbed(): void
    {
        $error = self::map(401, ['error' => Tokens::TEST, 'code' => 2000]);

        self::assertSame('hsr…[redacted]', $error->errorCode);
    }

    public function testANonIntegerCodeIsNull(): void
    {
        self::assertNull(self::map(400, ['error' => 'x', 'code' => '12'])->numericCode);
    }

    /** @return iterable<string, array{string, string, string|null}> */
    public static function operations(): iterable
    {
        yield 'plain' => ['GET', '/v1/account', 'GET /v1/account'];
        yield 'templated' => ['GET', '/v1/jobs/abc', 'GET /v1/jobs/{id}'];
        yield 'trailing slash' => ['get', '/v1/account/', 'GET /v1/account'];
        yield 'wrong method' => ['DELETE', '/v1/account', null];
        yield 'unknown' => ['GET', '/v1/unknown', null];
        yield 'extra segment' => ['GET', '/v1/jobs/abc/def', null];
    }

    #[DataProvider('operations')]
    public function testOperationKeys(string $method, string $path, ?string $expected): void
    {
        $key = ErrorMapper::operationKey($method, $path);

        if ($expected === 'GET /v1/jobs/{id}') {
            self::assertNotNull($key);
            self::assertStringStartsWith('GET /v1/jobs/{', $key);

            return;
        }
        self::assertSame($expected, $key);
    }

    public function testSnakeCasing(): void
    {
        self::assertSame('retry_after_seconds', ErrorMapper::snake('retryAfterSeconds'));
        self::assertSame('already_snake', ErrorMapper::snake('already_snake'));
        self::assertSame('abc', ErrorMapper::snake('ABC') === 'abc' ? 'abc' : 'x');
    }
}
