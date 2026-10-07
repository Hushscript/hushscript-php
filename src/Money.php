<?php

declare(strict_types=1);

namespace Hushscript;

/**
 * A price as the API states it, with a display string in the `en` locale.
 *
 * The amount digits and the grouping (minor-unit digits from Stripe's table, thousands grouped with
 * commas) are the same in every Hushscript SDK. The rest of `formatted` is this SDK's own: it writes
 * `$`, `€`, `£` or `¥` for USD, EUR, GBP and JPY, and for every other known currency the code and a
 * no-break space before the number (`KRW 1,234`), where the TypeScript SDK takes its symbols from Intl
 * and may print another. Compare `amount` and `currency`, never `formatted`, across SDKs.
 */
final readonly class Money
{
    // Stripe's minor-unit digits, which the API's amounts use (spec 6.5). They come from these tables and
    // never from locale data: ICU disagrees for IDR, HUF, ISK and others.
    private const ZERO_DIGITS = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE_DIGITS = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    // The ISO 4217 codes printed with the code in front; any other string is shown plainly, as "5.99 ZZZ", and never fails.
    private const KNOWN = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD',
        'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY',
        'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP',
        'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR',
        'IQD', 'IRR', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT',
        'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR',
        'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK',
        'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD',
        'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY',
        'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF',
        'XPF', 'YER', 'ZAR', 'ZMW', 'ZWG',
    ];

    private const SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥'];

    private const NO_BREAK_SPACE = "\u{A0}";

    /**
     * @param  int  $amount  in the currency's minor units, from the API
     * @param  string  $currency  the ISO 4217 code as the API sends it
     * @param  string  $formatted  `amount` divided by Stripe's minor-unit digits for the currency, for example `$5.99`
     */
    private function __construct(
        public int $amount,
        public string $currency,
        public string $formatted,
    ) {
    }

    /**
     * The `Money` of an API amount and currency. An unknown currency is shown as the plain amount and the
     * upper-cased code with 2 digits, and never raises. The digits are split as text, so no amount, the
     * int64 extremes included, passes through a float or a negation.
     *
     * @internal
     */
    public static function of(int $amount, string $currency): self
    {
        $code = strtoupper($currency);
        $digits = in_array($code, self::ZERO_DIGITS, true) ? 0 : (in_array($code, self::THREE_DIGITS, true) ? 3 : 2);
        $magnitude = str_pad(ltrim((string) $amount, '-'), $digits + 1, '0', STR_PAD_LEFT);
        $whole = $digits === 0 ? $magnitude : substr($magnitude, 0, -$digits);
        $known = in_array($code, self::KNOWN, true);
        $number = $known ? self::grouped($whole) : $whole;
        if ($digits > 0) {
            $number .= '.'.substr($magnitude, -$digits);
        }
        $sign = $amount < 0 ? '-' : '';
        if (! $known) {
            return new self($amount, $currency, $sign.$number.' '.$code);
        }

        return new self($amount, $currency, $sign.(isset(self::SYMBOLS[$code]) ? self::SYMBOLS[$code] : $code.self::NO_BREAK_SPACE).$number);
    }

    private static function grouped(string $digits): string
    {
        return strrev(implode(',', str_split(strrev($digits), 3)));
    }
}
