<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Generated\Hints;
use Hushscript\Hint;
use Hushscript\Tests\Support\Conformance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HintTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        foreach (Conformance::json('fixtures/hint-rendering.json') as $index => $case) {
            if (is_array($case)) {
                /** @var array<string, mixed> $case */
                yield $index.' '.json_encode($case['slug'] ?? '').' '.json_encode($case['details'] ?? []) => [$case];
            }
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testRenderingMatchesTheConformanceFixture(array $case): void
    {
        $details = is_array($case['details'] ?? null) ? $case['details'] : [];
        /** @var array<string, mixed> $details */
        $scope = isset($case['scope']) && is_string($case['scope']) ? $case['scope'] : null;

        self::assertIsString($case['slug']);
        self::assertSame($case['expected'], Hint::render($case['slug'], $details, null, $scope));
    }

    public function testTheGeneratedTableIsTheConformanceHintsFile(): void
    {
        self::assertSame(Conformance::json('hints.json'), Hints::TABLE);
    }

    public function testAnExplicitRetryAfterFillsThePlaceholder(): void
    {
        self::assertSame(
            'Too many requests. The SDK already waited and retried; slow down or try again in 61 s.',
            Hint::render('rate_limited', [], 61),
        );
    }

    public function testFractionalRetryAfterSecondsRoundUp(): void
    {
        self::assertSame(
            'Too many requests. The SDK already waited and retried; slow down or try again in 3 s.',
            Hint::render('rate_limited', ['retry_after_seconds' => 2.1]),
        );
    }

    public function testTheScopeComesFromTheOperationMap(): void
    {
        $hint = Hint::render('pat_scope_missing', [], null, 'billing:write');

        self::assertNotNull($hint);
        self::assertStringStartsWith('This call needs the "billing:write" permission.', $hint);
    }

    public function testAnUnlistedSlugHasNoHint(): void
    {
        self::assertNull(Hint::render('not_a_slug', ['needed_seconds' => 5]));
    }

    public function testValuesOfEveryShapeAreFormatted(): void
    {
        // Not a hint of the table: the formatting is exercised through allowed_packs and current_version.
        self::assertSame('New agent accounts can buy 45min until earlier purchases settle.', Hint::render('agent_pack_locked', ['allowed_packs' => ['45min']]));
        self::assertSame('Accept the current terms version: 7.', Hint::render('policy_version_stale', ['current_version' => 7]));
        self::assertSame('Accept the current terms version: 2.5.', Hint::render('policy_version_stale', ['current_version' => 2.5]));
        self::assertSame('Accept the current terms version: 3.', Hint::render('policy_version_stale', ['current_version' => 3.0]));
        self::assertSame('Accept the current terms version: true.', Hint::render('policy_version_stale', ['current_version' => true]));
        self::assertSame('Accept the current terms version: false.', Hint::render('policy_version_stale', ['current_version' => false]));
        self::assertSame('Accept the current terms version: 1, 2, .', Hint::render('policy_version_stale', ['current_version' => [1, 2, new \stdClass()]]));
        self::assertSame('Accept the current terms version: .', Hint::render('policy_version_stale', ['current_version' => new \stdClass()]));
    }

    public function testMinutesRoundTheRightWay(): void
    {
        self::assertSame(
            'Not enough minutes: 2 needed, 0 available. Buy a pack with hs.buy("300min") or in the app.',
            Hint::render('insufficient_balance', ['needed_seconds' => 61, 'balance_seconds' => 59.0]),
        );
    }

    public function testNonNumericSecondsAreIgnored(): void
    {
        self::assertSame('Buy a pack with hs.buy("300min") or in the app.', Hint::render('insufficient_balance', ['needed_seconds' => '61', 'balance_seconds' => 59]));
    }
}
