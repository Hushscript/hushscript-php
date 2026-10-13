<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Http\BadBody;
use Hushscript\Http\Body;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Each accessor either returns the type asked for or raises BadBody naming the field and what was expected. */
final class BodyTest extends TestCase
{
    /** @param array<mixed> $fields */
    private static function body(array $fields): Body
    {
        return Body::of($fields, 'response');
    }

    /** @return iterable<string, array{\Closure(): mixed, string}> */
    public static function refusals(): iterable
    {
        yield 'not an object' => [static fn (): Body => Body::of(5, 'response'), 'response: expected an object'];
        yield 'a string is not an object' => [static fn (): Body => Body::of('text', 'answer'), 'answer: expected an object'];
        yield 'list item not an object' => [static fn (): array => self::body(['xs' => [['a' => 1], 5]])->list('xs'), 'response.xs[1]: expected an object'];
        yield 'list item field' => [static fn (): string => self::body(['xs' => [['name' => 'a'], ['name' => 5]]])->list('xs')[1]->string('name'), 'response.xs[1].name: expected a string'];
        yield 'list that is an object of objects' => [static fn (): array => self::body(['xs' => ['a' => ['x' => 1]]])->list('xs'), 'response.xs: expected an array'];
        yield 'list missing' => [static fn (): array => self::body([])->list('xs'), 'response.xs: expected a value'];
        yield 'list null' => [static fn (): array => self::body(['xs' => null])->list('xs'), 'response.xs: expected an array or object'];
        yield 'list scalar' => [static fn (): array => self::body(['xs' => 5])->list('xs'), 'response.xs: expected an array or object'];
        yield 'strings that is an object' => [static fn (): array => self::body(['tags' => ['a' => 'x']])->strings('tags'), 'response.tags: expected an array'];
        yield 'strings item' => [static fn (): array => self::body(['tags' => ['x', 5]])->strings('tags'), 'response.tags[1]: expected a string'];
        yield 'string record value' => [static fn (): array => self::body(['names' => ['A' => 'Ann', 'B' => 7]])->stringRecord('names'), 'response.names.B: expected a string'];
        yield 'records value' => [static fn (): array => self::body(['speakers' => ['A' => ['n' => 1], 'B' => 'x']])->records('speakers'), 'response.speakers.B: expected an object'];
        yield 'records child field' => [static fn (): string => self::body(['speakers' => ['A' => ['name' => 'a'], 'B' => ['name' => 1]]])->records('speakers')['B']->string('name'), 'response.speakers.B.name: expected a string'];
        yield 'child scalar' => [static fn (): Body => self::body(['meta' => 'x'])->child('meta'), 'response.meta: expected an array or object'];
        yield 'opaque scalar' => [static fn (): ?array => self::body(['meta' => 'x'])->opaque('meta'), 'response.meta: expected an object or null'];
        yield 'string int' => [static fn (): string => self::body(['id' => 5])->string('id'), 'response.id: expected a string'];
        yield 'string missing' => [static fn (): string => self::body([])->string('id'), 'response.id: expected a string'];
        yield 'count negative' => [static fn (): int => self::body(['n' => -1])->count('n'), 'response.n: expected a whole number of 0 or more'];
        yield 'floored text' => [static fn (): int => self::body(['ms' => 'x'])->floored('ms'), 'response.ms: expected a number'];
        yield 'floored missing' => [static fn (): int => self::body([])->floored('ms'), 'response.ms: expected a number'];
        yield 'floored bool' => [static fn (): int => self::body(['ms' => true])->floored('ms'), 'response.ms: expected a number'];
        yield 'floored infinite' => [static fn (): int => self::body(['ms' => INF])->floored('ms'), 'response.ms: expected a number'];
        yield 'floored not a number' => [static fn (): int => self::body(['ms' => NAN])->floored('ms'), 'response.ms: expected a number'];
        yield 'floored beyond int64' => [static fn (): int => self::body(['ms' => 1e19])->floored('ms'), 'response.ms: expected a number'];
        yield 'floored at the limit' => [static fn (): int => self::body(['ms' => 9.2e18])->floored('ms'), 'response.ms: expected a number'];
        yield 'floored at the negative limit' => [static fn (): int => self::body(['ms' => -9.2e18])->floored('ms'), 'response.ms: expected a number'];
        yield 'timestamp before the epoch' => [static fn (): \DateTimeImmutable => self::body(['at' => -1])->timestamp('at'), 'response.at: expected a unix timestamp in seconds'];
        yield 'timestamp after year 9999' => [static fn (): \DateTimeImmutable => self::body(['at' => Body::MAX_UNIX_SECONDS + 1])->timestamp('at'), 'response.at: expected a unix timestamp in seconds'];
    }

    /** @param \Closure(): mixed $read */
    #[DataProvider('refusals')]
    public function testARefusalIsABadBodyNamingTheField(\Closure $read, string $message): void
    {
        try {
            $read();
            self::fail('expected BadBody');
        } catch (\Throwable $error) {
            self::assertSame(BadBody::class, $error::class, $error->getMessage());
            self::assertSame($message, $error->getMessage());
        }
    }

    public function testEveryEntryOfAStringRecordComesBack(): void
    {
        self::assertSame(['A' => 'Ann', 'B' => 'Bob'], self::body(['names' => ['A' => 'Ann', 'B' => 'Bob']])->stringRecord('names'));
    }

    public function testAnOpaqueObjectComesBackAsSentAndNullOrMissingAsNull(): void
    {
        self::assertSame(['a' => [1, 'x']], self::body(['meta' => ['a' => [1, 'x']]])->opaque('meta'));
        self::assertNull(self::body(['meta' => null])->opaque('meta'));
        self::assertNull(self::body([])->opaque('meta'));
    }

    /** @return iterable<string, array{int|float, int}> */
    public static function flooredValues(): iterable
    {
        yield 'whole' => [7, 7];
        yield 'one and a half' => [1.5, 1];
        yield 'just under two' => [1.9, 1];
        yield 'negative half' => [-0.5, -1];
        yield 'largest under the limit' => [9_199_999_999_999_998_976.0, 9_199_999_999_999_998_976];
        yield 'smallest over the negative limit' => [-9_199_999_999_999_998_976.0, -9_199_999_999_999_998_976];
    }

    #[DataProvider('flooredValues')]
    public function testFlooredRoundsDown(int|float $value, int $expected): void
    {
        self::assertSame($expected, self::body(['ms' => $value])->floored('ms'));
    }

    public function testTimestampsFromTheEpochToTheEndOfYear9999AreAccepted(): void
    {
        self::assertSame('1970-01-01T00:00:00+00:00', self::body(['at' => 0])->timestamp('at')->format(DATE_ATOM));
        self::assertSame('9999-12-31T23:59:59+00:00', self::body(['at' => Body::MAX_UNIX_SECONDS])->timestamp('at')->format(DATE_ATOM));
    }
}
