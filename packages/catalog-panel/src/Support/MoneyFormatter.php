<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Support;

/**
 * Money formatting and parsing utility using strictly integer and exact string arithmetic.
 * Never uses floating-point numbers or casting to prevent precision drift.
 */
final class MoneyFormatter
{
    /**
     * Format integer minor units into human-friendly currency display string.
     * Example: 1300, 'EUR' -> '€13.00'
     */
    public static function format(?int $minorUnits, ?string $currency): ?string
    {
        if ($minorUnits === null || $currency === null || trim($currency) === '') {
            return null;
        }

        $currency = strtoupper(trim($currency));
        $decimals = self::getDecimals($currency);

        if ($decimals === 0) {
            $formatted = number_format($minorUnits);
        } else {
            $divisor = 10 ** $decimals;
            $major = intdiv($minorUnits, $divisor);
            $fraction = abs($minorUnits % $divisor);
            $formatted = number_format($major) . '.' . str_pad((string) $fraction, $decimals, '0', STR_PAD_LEFT);
        }

        $symbol = self::getSymbol($currency);
        if ($symbol !== null) {
            return $symbol . $formatted;
        }

        return $formatted . ' ' . $currency;
    }

    /**
     * Convert human-entered decimal string or integer into integer minor units using exact string arithmetic.
     * Rejects floats, malformed strings, and over-precision input.
     *
     * Examples (2 decimals):
     *   "13"    -> 1300
     *   "13.0"  -> 1300
     *   "13.00" -> 1300
     *   "13.10" -> 1310
     *   "0.01"  -> 1
     */
    public static function toMinorUnits(string|int $amountInput, string $currency): int
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException("Invalid 3-letter currency code: '{$currency}'");
        }

        $decimals = self::getDecimals($currency);

        if (is_int($amountInput)) {
            if ($amountInput < 0) {
                throw new \InvalidArgumentException("Money amount cannot be negative: {$amountInput}");
            }
            return $amountInput * (10 ** $decimals);
        }

        $clean = trim($amountInput);
        $clean = str_replace([' ', ','], '', $clean);

        if ($clean === '') {
            throw new \InvalidArgumentException("Money amount cannot be empty.");
        }

        // Validate non-negative decimal string pattern
        if (!preg_match('/^\d+(\.\d+)?$/D', $clean)) {
            throw new \InvalidArgumentException("Malformed money amount: '{$amountInput}'. Expected non-negative numeric string.");
        }

        $parts = explode('.', $clean, 2);
        $intPart = $parts[0];
        $decPart = $parts[1] ?? '';

        if (strlen($decPart) > $decimals) {
            throw new \InvalidArgumentException(
                "Excess decimal precision for currency {$currency}: expected at most {$decimals} decimal places, received " . strlen($decPart) . " ('{$clean}')."
            );
        }

        if ($decimals === 0) {
            if ($decPart !== '' && (int) $decPart > 0) {
                throw new \InvalidArgumentException("Currency {$currency} does not support decimal fractions.");
            }
            return (int) $intPart;
        }

        $paddedDec = str_pad($decPart, $decimals, '0', STR_PAD_RIGHT);

        return ((int) $intPart * (10 ** $decimals)) + (int) $paddedDec;
    }

    /**
     * Convert integer minor units to major string suitable for HTML form inputs without floats.
     * Example: 1300, 'EUR' -> '13.00'
     */
    public static function toMajorString(?int $minorUnits, ?string $currency): string
    {
        if ($minorUnits === null) {
            return '';
        }

        $currency = $currency !== null ? strtoupper(trim($currency)) : 'EUR';
        $decimals = self::getDecimals($currency);

        if ($decimals === 0) {
            return (string) $minorUnits;
        }

        $divisor = 10 ** $decimals;
        $major = intdiv($minorUnits, $divisor);
        $fraction = abs($minorUnits % $divisor);

        return $major . '.' . str_pad((string) $fraction, $decimals, '0', STR_PAD_LEFT);
    }

    public static function getDecimals(string $currency): int
    {
        return match (strtoupper($currency)) {
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF', 'IRR' => 0,
            'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' => 3,
            default => 2,
        };
    }

    public static function getSymbol(string $currency): ?string
    {
        return match (strtoupper($currency)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'JPY' => '¥',
            default => null,
        };
    }
}
