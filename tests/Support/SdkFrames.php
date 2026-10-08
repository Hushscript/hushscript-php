<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * With trace arguments kept (phpunit.xml, as in CI), an error's trace runs on past the SDK into the test method
 * and the test runner, whose arguments hold provider data, mocks and every other test of the suite. Those frames
 * are the caller's own data, not the SDK's, so a dump test cuts them off and checks what the SDK put there: R102
 * covers the SDK frames, from where the error was built up to the call the test made.
 */
final class SdkFrames
{
    /**
     * Cuts the trace of the error and of each previous one at the first frame of the test case or PHPUnit, and
     * returns the error. Fails when no SDK frame is left to check.
     *
     * @template T of \Throwable
     *
     * @param  T  $error
     * @return T
     */
    public static function only(\Throwable $error): \Throwable
    {
        for ($link = $error; $link !== null; $link = $link->getPrevious()) {
            $kept = [];
            foreach ($link->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (str_starts_with($class, 'PHPUnit\\') || is_subclass_of($class, TestCase::class)) {
                    break;
                }
                $kept[] = $frame;
            }
            (new \ReflectionProperty($link instanceof \Exception ? \Exception::class : \Error::class, 'trace'))->setValue($link, $kept);
        }
        $sdk = array_filter($error->getTrace(), static function (array $frame): bool {
            $class = $frame['class'] ?? '';

            return str_starts_with($class, 'Hushscript\\') && ! str_starts_with($class, 'Hushscript\\Tests\\');
        });
        Assert::assertNotSame([], $sdk, 'the trace keeps the SDK frames to check');

        return $error;
    }
}
