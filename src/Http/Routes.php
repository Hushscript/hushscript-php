<?php

declare(strict_types=1);

namespace Hushscript\Http;

/**
 * The operation class of a request comes from its method and the actual URL path (spec 7.4), never
 * from a caller-side tag.
 */
final class Routes
{
    public const CLAIM = 'claim';

    public const ROTATE = 'rotate';

    public const SIGNUP = 'signup';

    private const JOB = '/v1/uploads/[^/]+';

    private function __construct()
    {
    }

    /**
     * @return array{RetryClass, string|null}|null  the class and, for a credential-minting route, its name
     */
    private static function route(string $method, string $path): ?array
    {
        $path = self::normalize($path);
        $routes = [
            ['POST', '~^'.self::JOB.'/heartbeat$~D', RetryClass::Safe, null],
            ['PUT', '~^'.self::JOB.'/parts/[^/]+$~D', RetryClass::Part, null],
            ['POST', '~^'.self::JOB.'/complete$~D', RetryClass::Complete, null],
            ['POST', '~^/v1/uploads$~D', RetryClass::Create, null],
            ['POST', '~^/v1/billing/(purchase|setup-session)$~D', RetryClass::Purchase, null],
            ['POST', '~^/v1/agent/accounts/[^/]+/claim$~D', RetryClass::Never, self::CLAIM],
            ['POST', '~^/v1/agent/credentials/rotate$~D', RetryClass::Never, self::ROTATE],
            ['POST', '~^/v1/agent/accounts$~D', RetryClass::Never, self::SIGNUP],
        ];
        foreach ($routes as [$routeMethod, $pattern, $class, $name]) {
            if (strtoupper($method) === $routeMethod && preg_match($pattern, $path) === 1) {
                return [$class, $name];
            }
        }

        return null;
    }

    /** The path without trailing slashes; the root stays "/". */
    public static function normalize(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    public static function classify(string $method, string $path): RetryClass
    {
        $route = self::route($method, $path);
        if ($route !== null) {
            return $route[0];
        }

        return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true) ? RetryClass::Safe : RetryClass::Mutate;
    }

    /** `claim`, `rotate` or `signup` when the request is one of the spec 7.4 routes. */
    public static function unrecoverable(string $method, string $path): ?string
    {
        return self::route($method, $path)[1] ?? null;
    }
}
