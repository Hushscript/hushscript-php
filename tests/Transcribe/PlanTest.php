<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\ApiError;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\UploadError;
use Hushscript\Secret;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Transcribe\Created;
use Hushscript\Transcribe\PartRun;
use Hushscript\Transcribe\Plan;
use Hushscript\Transcribe\Shape;
use Hushscript\Transcribe\UrlBook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase
{
    private const MIB = 1024 * 1024;

    /** @return iterable<string, array{int, int}> */
    public static function proxiedSizes(): iterable
    {
        yield 'one byte' => [1, 1];
        yield 'exactly one part' => [32 * self::MIB, 1];
        yield 'one byte over one part' => [32 * self::MIB + 1, 2];
        yield 'one byte over two parts' => [64 * self::MIB + 1, 3];
    }

    #[DataProvider('proxiedSizes')]
    public function testAProxiedPlanCutsTheFileInto32MiBParts(int $size, int $parts): void
    {
        $plan = Plan::proxied($size);

        self::assertSame($parts, $plan->totalParts);
        self::assertSame(32 * self::MIB, $plan->partSize);
        self::assertNull($plan->nextPart);
        self::assertSame([], $plan->urls());
    }

    public function testAnEmptyFileIsStillOneProxiedPartOfNoBytes(): void
    {
        $plan = Plan::proxied(0);

        self::assertSame(1, $plan->totalParts);
        self::assertSame([0, 0], $plan->range(0, 1));
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function checkedPlans(): iterable
    {
        yield 'one full part' => [10, 1, true];
        yield 'one byte over a part, two parts' => [11, 2, true];
        yield 'one byte over a part, one part' => [11, 1, false];
        yield 'one byte over a part, three parts' => [11, 3, false];
        yield 'two full parts, two parts' => [20, 2, true];
        yield 'two full parts, three parts' => [20, 3, false];
    }

    #[DataProvider('checkedPlans')]
    public function testAPlanMustHaveOnePartPerPartSizeBytes(int $size, int $total, bool $fits): void
    {
        $plan = Plan::parse(['part_size_bytes' => 10, 'total_parts' => $total, 'part_urls' => []], 200);

        try {
            self::assertSame($plan, $plan->checkedFor($size));
            self::assertTrue($fits, 'expected bad_part_plan');
        } catch (UploadError $error) {
            self::assertFalse($fits, 'a plan that fits was refused');
            self::assertSame('bad_part_plan', $error->errorCode);
            self::assertSame("The API's upload plan does not describe the file's parts.", $error->getMessage());
        }
    }

    public function testEachPartRangeEndsAtTheFileAndAPartPastTheEndIsEmpty(): void
    {
        $plan = Plan::parse(['part_size_bytes' => 10, 'total_parts' => 3, 'part_urls' => []], 200);

        self::assertSame([0, 10], $plan->range(25, 1));
        self::assertSame([20, 5], $plan->range(25, 3));
        self::assertSame([30, 0], $plan->range(25, 4));
        self::assertSame([40, 0], $plan->range(25, 5));
    }

    public function testAParsedPlanKeepsItsCursorStatusAndUrls(): void
    {
        $plan = Plan::parse([
            'part_size_bytes' => 10,
            'total_parts' => 20,
            'part_urls' => [['n' => 1, 'url' => 'https://r2.example.com/1'], ['n' => 2, 'url' => 'https://r2.example.com/2']],
            'next_part_number' => 5,
        ], 201);

        self::assertSame(5, $plan->nextPart);
        self::assertSame(201, $plan->status);
        self::assertTrue($plan->directAvailable);
        self::assertSame([1, 2], array_keys($plan->urls()));
        self::assertSame('https://r2.example.com/2', $plan->urls()[2]->reveal());
    }

    public function testANullPartUrlListMeansNoDirectUpload(): void
    {
        $plan = Plan::parse(['part_size_bytes' => 10, 'total_parts' => 1, 'part_urls' => null], 200);

        self::assertFalse($plan->directAvailable);
        self::assertNull($plan->nextPart);
    }

    public function testEveryPlanKeepsItsOwnUrlsWhenAnotherIsBuilt(): void
    {
        $first = new Plan(10, 1, [1 => new Secret('https://r2.example.com/first')], null);
        $second = new Plan(10, 1, [1 => new Secret('https://r2.example.com/second')], null);

        self::assertSame('https://r2.example.com/first', $first->urls()[1]->reveal());
        self::assertSame('https://r2.example.com/second', $second->urls()[1]->reveal());
    }

    /** @return iterable<string, array{mixed}> */
    public static function partUrlsThatAreNotALists(): iterable
    {
        yield 'string' => ['x'];
        yield 'number' => [5];
    }

    #[DataProvider('partUrlsThatAreNotALists')]
    public function testPartUrlsThatAreNotAListAreABadResponse(mixed $urls): void
    {
        try {
            Plan::parse(['part_size_bytes' => 10, 'total_parts' => 1, 'part_urls' => $urls], 201);
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(201, $error->status);
            self::assertSame('The API answered 201 with an upload plan the SDK cannot use.', $error->getMessage());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function scalarUploads(): iterable
    {
        yield 'string' => ['x'];
        yield 'number' => [5];
        yield 'true' => [true];
    }

    #[DataProvider('scalarUploads')]
    public function testACreateWhoseUploadIsNotAnObjectIsABadResponse(mixed $upload): void
    {
        try {
            Created::parse(['job_id' => 'job_1', 'state' => 'uploading', 'upload' => $upload], 201);
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(201, $error->status);
            self::assertSame('The API answered 201 with an upload plan the SDK cannot use.', $error->getMessage());
        }
    }

    public function testACreateKeepsTheStateItNamesAndTakesUploadingWhenItNamesNone(): void
    {
        $processing = Created::parse(['job_id' => 'job_1', 'state' => 'processing', 'upload' => null], 200);
        $unnamed = Created::parse(['job_id' => 'job_2'], 200);

        self::assertSame(['job_1', 'processing', null], [$processing->jobId, $processing->state, $processing->plan]);
        self::assertSame(['job_2', 'uploading', null], [$unnamed->jobId, $unnamed->state, $unnamed->plan]);
    }

    public function testAnUnusableBodyNamesTheStatusTheApiAnsweredAndWhatWasWrong(): void
    {
        $error = Shape::bad('a part URL list', 202);

        self::assertSame('The API answered 202 with a part URL list the SDK cannot use.', $error->getMessage());
        self::assertSame('bad_response', $error->errorCode);
        self::assertSame(202, $error->status);
    }

    public function testAnIdThatCannotBeAPathSegmentIsRefusedByName(): void
    {
        try {
            Shape::segment('..', 'job id');
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('job id must be a non-empty string without a slash that is not "." or "..".', $error->getMessage());
            self::assertSame('invalid_option', $error->errorCode);
        }
    }

    public function testAUrlIsUsableForExactly23Hours(): void
    {
        $clock = new FakeClock();
        $clock->time = 50_000.0;
        $book = new UrlBook($clock);
        $book->store([7 => new Secret('https://r2.example.com/7')], 9);

        $clock->time = 50_000.0 + 23 * 3600;
        self::assertSame('https://r2.example.com/7', $book->get(7)?->reveal());
        self::assertSame(9, $book->nextStart);

        $clock->time = 50_000.0 + 23 * 3600 + 0.001;
        self::assertNull($book->get(7));
        self::assertSame('https://r2.example.com/7', $book->current(7)?->reveal());
    }

    public function testAPartTheBookNeverHeldHasNoUrl(): void
    {
        $book = new UrlBook(new FakeClock());

        self::assertNull($book->current(7));
        self::assertNull($book->get(7));
    }

    public function testADumpShowsTheShapeButNoUrlOrPartBytes(): void
    {
        $run = new PartRun(3, 'abcde');
        $run->proxied = true;
        $run->attempt = 2;
        $plan = Plan::parse(['part_size_bytes' => 10, 'total_parts' => 4, 'part_urls' => [['n' => 1, 'url' => 'https://r2.example.com/1']]], 200);

        self::assertSame(['n' => 3, 'length' => 5, 'proxied' => true, 'attempt' => 2, 'done' => false], $run->__debugInfo());
        self::assertSame(['partSize' => '10', 'totalParts' => '4', 'urls' => '…[redacted]'], $plan->__debugInfo());
        self::assertSame(['urls' => '…[redacted]'], (new UrlBook(new FakeClock()))->__debugInfo());
    }

    public function testAPlanAndAUrlBookRefuseToBeSerializedOrUnserialized(): void
    {
        $plan = Plan::parse(['part_size_bytes' => 10, 'total_parts' => 1, 'part_urls' => []], 200);
        $book = new UrlBook(new FakeClock());
        $refusals = [];
        foreach ([
            static fn (): string => serialize($plan),
            static fn (): string => serialize($book),
            static fn (): mixed => unserialize('O:'.strlen(Plan::class).':"'.Plan::class.'":0:{}'),
            static fn (): mixed => unserialize('O:'.strlen(UrlBook::class).':"'.UrlBook::class.'":0:{}'),
        ] as $attempt) {
            try {
                $attempt();
                $refusals[] = 'accepted';
            } catch (\LogicException $refused) {
                $refusals[] = $refused->getMessage();
            }
        }

        self::assertSame([
            'Plan cannot be serialized.',
            'UrlBook cannot be serialized.',
            'Plan cannot be unserialized.',
            'UrlBook cannot be unserialized.',
        ], $refusals);
    }
}
