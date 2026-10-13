<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Prices are formatted from Stripe's minor-unit table, never from locale data (spec 6.5). */
final class MoneyTest extends TestCase
{
    private const NBSP = "\u{A0}";

    /** @return iterable<string, array{int, string, string}> */
    public static function amounts(): iterable
    {
        yield 'usd' => [599, 'USD', '$5.99'];
        yield 'one cent' => [1, 'USD', '$0.01'];
        yield 'zero' => [0, 'USD', '$0.00'];
        yield 'grouped' => [150_000, 'USD', '$1,500.00'];
        yield 'jpy has no digits' => [500, 'JPY', '¥500'];
        yield 'kwd has three' => [1500, 'KWD', 'KWD'.self::NBSP.'1.500'];
        yield 'mxn' => [123456, 'MXN', 'MXN'.self::NBSP.'1,234.56'];
        yield 'inr' => [123456, 'INR', 'INR'.self::NBSP.'1,234.56'];
        yield 'brl' => [123456, 'BRL', 'BRL'.self::NBSP.'1,234.56'];
        yield 'cad' => [123456, 'CAD', 'CAD'.self::NBSP.'1,234.56'];
        yield 'aud' => [123456, 'AUD', 'AUD'.self::NBSP.'1,234.56'];
        yield 'chf' => [123456, 'CHF', 'CHF'.self::NBSP.'1,234.56'];
        yield 'eur' => [3499, 'EUR', '€34.99'];
        yield 'gbp' => [1250, 'GBP', '£12.50'];
        // Stripe counts two digits where ICU counts none, so ICU would print a price 100 times too large.
        yield 'idr' => [1_500_000, 'IDR', 'IDR'.self::NBSP.'15,000.00'];
        yield 'huf' => [150_000, 'HUF', 'HUF'.self::NBSP.'1,500.00'];
        yield 'isk' => [150_000, 'ISK', 'ISK'.self::NBSP.'1,500.00'];
        yield 'cop' => [150_000, 'COP', 'COP'.self::NBSP.'1,500.00'];
        // ... and two where ICU counts three.
        yield 'lyd' => [1500, 'LYD', 'LYD'.self::NBSP.'15.00'];
        yield 'bhd' => [1500, 'BHD', 'BHD'.self::NBSP.'1.500'];
        yield 'lowercase code' => [250, 'usd', '$2.50'];
        yield 'negative' => [-599, 'USD', '-$5.99'];
        yield 'negative kwd' => [-1500, 'KWD', '-KWD'.self::NBSP.'1.500'];
        yield 'big' => [1_234_567_890, 'USD', '$12,345,678.90'];
        yield 'int64 max' => [PHP_INT_MAX, 'USD', '$92,233,720,368,547,758.07'];
        yield 'int64 min' => [PHP_INT_MIN, 'USD', '-$92,233,720,368,547,758.08'];
        yield 'int64 max jpy' => [PHP_INT_MAX, 'JPY', '¥9,223,372,036,854,775,807'];
        yield 'int64 min kwd' => [PHP_INT_MIN, 'KWD', '-KWD'.self::NBSP.'9,223,372,036,854,775.808'];
        yield 'unknown code' => [599, 'ZZZ', '5.99 ZZZ'];
        yield 'unknown lowercase' => [599, 'us', '5.99 US'];
        yield 'empty code' => [599, '', '5.99 '];
        yield 'unknown negative is plain' => [-150_000, 'zzz', '-1500.00 ZZZ'];
        yield 'unknown is not grouped' => [123_456_789, 'ZZZ', '1234567.89 ZZZ'];
        yield 'fraction is padded' => [5, 'EUR', '€0.05'];
    }

    #[DataProvider('amounts')]
    public function testFormatted(int $amount, string $currency, string $expected): void
    {
        $money = Money::of($amount, $currency);

        self::assertSame($expected, $money->formatted);
        self::assertSame($amount, $money->amount);
        self::assertSame($currency, $money->currency, 'the code stays as the API sent it');
    }

    /** @return iterable<string, array{string}> */
    public static function zeroDigit(): iterable
    {
        foreach (explode(' ', 'BIF CLP DJF GNF KMF KRW MGA PYG RWF UGX VND VUV XAF XOF XPF') as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('zeroDigit')]
    public function testEveryZeroDigitCurrencyOfTheStripeTableHasNoFraction(string $code): void
    {
        self::assertSame($code.self::NBSP.'1,234', Money::of(1234, $code)->formatted);
    }

    /** @return iterable<string, array{string}> */
    public static function threeDigit(): iterable
    {
        foreach (explode(' ', 'BHD JOD KWD OMR TND') as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('threeDigit')]
    public function testEveryThreeDigitCurrencyOfTheStripeTableHasThree(string $code): void
    {
        self::assertSame($code.self::NBSP.'1.234', Money::of(1234, $code)->formatted);
    }

    public function testNothingDependsOnTheLocaleOrTheFloatPrecisionOfTheAmount(): void
    {
        // 2^53 + 1 is not a float: a conversion through float would print ...992.
        self::assertSame('$90,071,992,547,409.93', Money::of(9_007_199_254_740_993, 'USD')->formatted);
    }
}
