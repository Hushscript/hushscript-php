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
        // Methods that take an API body, a response or a failure that may quote a signed URL: a body is data of the account.
        yield 'Shape::record value' => [\Hushscript\Transcribe\Shape::class, 'record', 'value'];
        yield 'Pipeline::toTranscript body' => [\Hushscript\Transcribe\Pipeline::class, 'toTranscript', 'body'];
        yield 'Pipeline::finish first' => [\Hushscript\Transcribe\Pipeline::class, 'finish', 'first'];
        yield 'Wait::until first' => [\Hushscript\Transcribe\Wait::class, 'until', 'first'];
        yield 'Wait::transcriptId job' => [\Hushscript\Transcribe\Wait::class, 'transcriptId', 'job'];
        yield 'Preflight::account account' => [\Hushscript\Transcribe\Preflight::class, 'account', 'account'];
        yield 'Created::parse body' => [\Hushscript\Transcribe\Created::class, 'parse', 'body'];
        yield 'Plan::parse fields' => [\Hushscript\Transcribe\Plan::class, 'parse', 'fields'];
        yield 'Uploader::urlsFailed failure' => [\Hushscript\Transcribe\Uploader::class, 'urlsFailed', 'failure'];
        yield 'Uploader::terminal failure' => [\Hushscript\Transcribe\Uploader::class, 'terminal', 'failure'];
        yield 'Uploader::proxiedFailure answer' => [\Hushscript\Transcribe\Uploader::class, 'proxiedFailure', 'answer'];
        yield 'Uploader::acknowledged response' => [\Hushscript\Transcribe\Uploader::class, 'acknowledged', 'response'];
        yield 'Resume::jobMissing previous' => [\Hushscript\Transcribe\Resume::class, 'jobMissing', 'previous'];
        yield 'PartFailure::of failure' => [\Hushscript\Transcribe\PartFailure::class, 'of', 'failure'];
        yield 'RetryMiddleware::provablyNotSent failure' => [\Hushscript\Http\RetryMiddleware::class, 'provablyNotSent', 'failure'];
        yield 'RetryMiddleware::outcomeOf response' => [\Hushscript\Http\RetryMiddleware::class, 'outcomeOf', 'response'];
        yield 'ErrorMapper::fromResponse response' => [\Hushscript\Exception\ErrorMapper::class, 'fromResponse', 'response'];
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

    /** Parameter names that carry API data in any type (a string, an array, a response, an error). */
    private const BODY_NAMES = '/^(body|record|response|first|job|account|transcript|payload|fields|page|decoded|raw|created|answer|failure)$/i';

    /** Parameters whose names match but hold nothing secret, with the reason. */
    private const NOT_SECRET = [
        'Hushscript\\Api::__construct:$baseUrl' => 'the configured API origin',
        'Hushscript\\Config::__construct:$baseUrl' => 'the configured API origin',
        'Hushscript\\Http\\RetryMiddleware::__construct:$baseUrl' => 'the configured API origin',
        'Hushscript\\Transcribe\\Pipeline::__construct:$baseUrl' => 'the configured API origin',
        'Hushscript\\Transcribe\\Uploader::__construct:$baseUrl' => 'the configured API origin',
        'Hushscript\\Client::transcribe:$path' => 'a file path of the caller',
        'Hushscript\\Transcribe\\Pipeline::transcribe:$path' => 'a file path of the caller',
        'Hushscript\\Transcribe\\Pipeline::resume:$path' => 'a file path of the caller',
        'Hushscript\\Transcribe\\Source::__construct:$path' => 'a file path of the caller',
        'Hushscript\\Transcribe\\Source::open:$path' => 'a file path of the caller',
        'Hushscript\\Transcribe\\Source::sizeOf:$path' => 'a file path of the caller',
        'Hushscript\\Transcribe\\Source::unreadable:$path' => 'a file path of the caller',
        'Hushscript\\Transcribe\\Probe::container:$path' => 'a file path of the caller',
        'Hushscript\\Transcribe\\Probe::scanMp3:$path' => 'a file path of the caller',
        'Hushscript\\Transcribe\\Probe::opusSeconds:$path' => 'a file path of the caller',
    ];

    /** @return iterable<string, array{string, string, string}> */
    public static function everyParameter(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Generated/')) {
                continue;
            }
            $relative = substr((string) realpath($file->getPathname()), strlen((string) realpath(__DIR__.'/../src')) + 1, -4);
            $class = 'Hushscript\\'.str_replace('/', '\\', $relative);
            if (! class_exists($class) && ! interface_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if (self::takesString($parameter) || self::carriesBody($parameter)) {
                        yield $class.'::'.$method->getName().':$'.$parameter->getName() => [$class, $method->getName(), $parameter->getName()];
                    }
                }
            }
        }
    }

    /** Whatever its type, a parameter named for an API body, response or payload may hold one. */
    private static function carriesBody(\ReflectionParameter $parameter): bool
    {
        return preg_match(self::BODY_NAMES, $parameter->getName()) === 1;
    }

    private static function takesString(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        $names = $type instanceof \ReflectionUnionType ? $type->getTypes() : ($type === null ? [] : [$type]);
        foreach ($names as $name) {
            if ($name instanceof \ReflectionNamedType && $name->getName() === 'string') {
                return true;
            }
        }

        return false;
    }

    public function testTheAllowlistHasNoStaleEntry(): void
    {
        $keys = [];
        foreach (self::everyParameter() as $key => $case) {
            $keys[$key] = true;
        }
        foreach (array_keys(self::NOT_SECRET) as $key) {
            self::assertArrayHasKey($key, $keys, $key.' is no longer a string parameter');
        }
    }

    /**
     * Whack-a-mole is over: any string parameter that is named like a secret carries the attribute, so a
     * new one cannot be added without it. Values that travel through several frames are Secret objects instead.
     *
     * @param  class-string  $class
     */
    #[DataProvider('everyParameter')]
    public function testEveryParameterNamedLikeASecretIsMarkedSensitive(string $class, string $method, string $parameter): void
    {
        if (preg_match('/token|secret|url|authorization|pat|claim/i', $parameter) !== 1 && preg_match(self::BODY_NAMES, $parameter) !== 1) {
            $this->addToAssertionCount(1);

            return;
        }
        $key = $class.'::'.$method.':$'.$parameter;
        if (isset(self::NOT_SECRET[$key])) {
            $this->addToAssertionCount(1);

            return;
        }
        $reflected = null;
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $candidate) {
            if ($candidate->getName() === $parameter) {
                $reflected = $candidate;
            }
        }
        self::assertNotNull($reflected);
        self::assertCount(1, $reflected->getAttributes(\SensitiveParameter::class), $key.' can hold a secret: mark it #[\\SensitiveParameter] or pass it as a Hushscript\\Secret');
    }
}
