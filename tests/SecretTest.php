<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Clock;
use Hushscript\Secret;
use Hushscript\Transcribe\PartRun;
use Hushscript\Transcribe\Plan;
use Hushscript\Transcribe\Tracker;
use Hushscript\Transcribe\UrlBook;
use PHPUnit\Framework\TestCase;

final class SecretTest extends TestCase
{
    private const VALUE = 'https://r2.example.com/b/1?X-Amz-Signature=SECRET-VALUE';

    /** @return list<string> every way a value can be turned into text */
    private static function dumps(mixed $subject): array
    {
        $dumps = [print_r($subject, true), var_export($subject, true), (string) json_encode($subject, JSON_PARTIAL_OUTPUT_ON_ERROR), (string) json_encode((array) $subject, JSON_PARTIAL_OUTPUT_ON_ERROR)];
        ob_start();
        var_dump($subject);
        $dumps[] = (string) ob_get_clean();
        try {
            $dumps[] = serialize($subject);
        } catch (\Throwable) {
            // refusing to serialize is the safe answer
        }

        return $dumps;
    }

    public function testASecretGivesItsValueOnlyToReveal(): void
    {
        $secret = new Secret(self::VALUE);

        self::assertSame(self::VALUE, $secret->reveal());
        self::assertTrue($secret->equals(new Secret(self::VALUE)));
        self::assertFalse($secret->equals(new Secret('other')));
        foreach (self::dumps($secret) as $dump) {
            self::assertStringNotContainsString('SECRET-VALUE', $dump);
            self::assertStringNotContainsString('X-Amz-Signature', $dump);
        }
    }

    public function testASecretCannotBeSerializedOrCloned(): void
    {
        $secret = new Secret(self::VALUE);

        try {
            serialize($secret);
            self::fail('serialize must refuse');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\LogicException::class);
        $copy = clone $secret;
        unset($copy);
    }

    public function testTheHoldersOfASignedUrlShowNoUrlInAnyDump(): void
    {
        $clock = new class () implements Clock {
            public function now(): float
            {
                return 0.0;
            }

            public function sleep(float $seconds): void
            {
            }
        };
        $book = new UrlBook($clock);
        $book->store([1 => new Secret(self::VALUE)], null);
        $run = new PartRun(1, 'bytes');
        $run->url = new Secret(self::VALUE);
        $plan = new Plan(1, 1, [1 => new Secret(self::VALUE)], null);
        $tracker = new Tracker('key', new Secret('resume-token-value'));

        foreach ([$book, $run, $plan, $tracker] as $holder) {
            foreach (self::dumps($holder) as $dump) {
                self::assertStringNotContainsString('SECRET-VALUE', $dump, $holder::class);
                self::assertStringNotContainsString('resume-token-value', $dump, $holder::class);
            }
        }
    }
}
