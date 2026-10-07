<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Http\RetryClass;
use Hushscript\Http\Routes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase
{
    /** @return iterable<string, array{string, string, RetryClass, string|null}> */
    public static function routes(): iterable
    {
        yield 'GET anything' => ['GET', '/v1/account', RetryClass::Safe, null];
        yield 'GET lower case' => ['get', '/v1/jobs/j1', RetryClass::Safe, null];
        yield 'HEAD' => ['HEAD', '/v1/account', RetryClass::Safe, null];
        yield 'OPTIONS' => ['OPTIONS', '/v1/account', RetryClass::Safe, null];
        yield 'GET on a mutation path' => ['GET', '/v1/billing/purchase', RetryClass::Safe, null];
        yield 'heartbeat' => ['POST', '/v1/uploads/j1/heartbeat', RetryClass::Safe, null];
        yield 'part' => ['PUT', '/v1/uploads/j1/parts/3', RetryClass::Part, null];
        yield 'complete' => ['POST', '/v1/uploads/j1/complete', RetryClass::Complete, null];
        yield 'create' => ['POST', '/v1/uploads', RetryClass::Create, null];
        yield 'create trailing slash' => ['POST', '/v1/uploads/', RetryClass::Create, null];
        yield 'purchase' => ['POST', '/v1/billing/purchase', RetryClass::Purchase, null];
        yield 'setup session' => ['POST', '/v1/billing/setup-session', RetryClass::Purchase, null];
        yield 'claim' => ['POST', '/v1/agent/accounts/sg_1/claim', RetryClass::Never, Routes::CLAIM];
        yield 'rotate' => ['POST', '/v1/agent/credentials/rotate', RetryClass::Never, Routes::ROTATE];
        yield 'signup' => ['POST', '/v1/agent/accounts', RetryClass::Never, Routes::SIGNUP];
        yield 'default card' => ['PUT', '/v1/billing/cards/default', RetryClass::Mutate, null];
        yield 'org' => ['POST', '/v1/org/members', RetryClass::Mutate, null];
        yield 'delete' => ['DELETE', '/v1/org/members/m1', RetryClass::Mutate, null];
        yield 'purchase with another method' => ['PUT', '/v1/billing/purchase', RetryClass::Mutate, null];
        yield 'part with POST' => ['POST', '/v1/uploads/j1/parts/3', RetryClass::Mutate, null];
        yield 'claim with an extra segment' => ['POST', '/v1/agent/accounts/sg_1/claim/x', RetryClass::Mutate, null];
        yield 'claim without an id' => ['POST', '/v1/agent/accounts//claim', RetryClass::Mutate, null];
        yield 'empty path' => ['POST', '', RetryClass::Mutate, null];
    }

    #[DataProvider('routes')]
    public function testClassification(string $method, string $path, RetryClass $class, ?string $route): void
    {
        self::assertSame($class, Routes::classify($method, $path));
        self::assertSame($route, Routes::unrecoverable($method, $path));
    }
}
