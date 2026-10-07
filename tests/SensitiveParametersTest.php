<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A parameter that can hold a token, claim secret, signed URL or resume token is hidden from stack
 * traces (the trace shows a SensitiveParameterValue instead of the value).
 */
final class SensitiveParametersTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, string}> */
    public static function parameters(): iterable
    {
        yield 'Api::request path' => [\Hushscript\Api::class, 'request', 'path'];
        yield 'Api::request query' => [\Hushscript\Api::class, 'request', 'query'];
        yield 'Api::request body' => [\Hushscript\Api::class, 'request', 'body'];
        yield 'Api::checkPath path' => [\Hushscript\Api::class, 'checkPath', 'path'];
        yield 'Api::queryString query' => [\Hushscript\Api::class, 'queryString', 'query'];
        yield 'RetryMiddleware::transmit outgoing' => [\Hushscript\Http\RetryMiddleware::class, 'transmit', 'outgoing'];
    }

    /** @param class-string $class */
    #[DataProvider('parameters')]
    public function testTheParameterIsMarkedSensitive(string $class, string $method, string $parameter): void
    {
        $found = null;
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $candidate) {
            if ($candidate->getName() === $parameter) {
                $found = $candidate;
            }
        }

        self::assertNotNull($found, "{$class}::{$method} has no \${$parameter}");
        self::assertCount(1, $found->getAttributes(\SensitiveParameter::class));
    }
}
