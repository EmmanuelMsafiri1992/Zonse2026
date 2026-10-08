<?php

namespace App\Support\Import;

use App\Support\Lists;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns spreadsheet text into stored values: amounts written any common way, dates in either
 * order or as Excel serial numbers, yes/no flags, and countries by name or code.
 */
class Values
{
    /** Normalise a column heading or a value for matching: lowercase letters and digits only. */
    public static function key(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii($value)));
    }

    /** Text as typed, with long numbers Excel turned into 1.23E+12 written out again. */
    public static function text(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d+(\.\d+)?E\+\d+$/i', $value)) {
            return sprintf('%.0f', (float) $value);
        }

        return $value;
    }

    /** "1,234.50", "1 234,50", "$1,234.50", "(12.00)" and "R 99" all read as numbers; null when it is not one. */
    public static function number(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $negative = (bool) preg_match('/^\(.*\)$/', $value) || str_contains($value, '-');
        $digits = (string) preg_replace('/[^0-9.,]/', '', $value);
        if ($digits === '' || ! preg_match('/\d/', $digits)) {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?E[+-]?\d+$/i', trim($value))) {
            return (float) $value;
        }

        $lastComma = strrpos($digits, ',');
        $lastDot = strrpos($digits, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $digits = str_replace([$thousands, $decimal], ['', '.'], $digits);
        } elseif ($lastComma !== false) {
            $digits = preg_match('/^\d+,\d{1,2}$/', $digits) ? str_replace(',', '.', $digits) : str_replace(',', '', $digits);
        } elseif (substr_count($digits, '.') > 1) {
            $digits = str_replace('.', '', $digits);
        }
        if (! is_numeric($digits)) {
            return null;
        }

        return $negative ? -(float) $digits : (float) $digits;
    }

    /** true / false for yes, no, y, n, 1, 0, true, false, active, inactive; null when unclear. */
    public static function boolean(string $value): ?bool
    {
        $value = self::key($value);

        return match (true) {
            in_array($value, ['yes', 'y', '1', 'true', 'active', 'on', 'x'], true) => true,
            in_array($value, ['no', 'n', '0', 'false', 'inactive', 'off'], true) => false,
            default => null,
        };
    }

    /**
     * A date as Y-m-d. Day-first or month-first decides "03/04/2026"; a first number over 12 settles it either way.
     * Excel serial numbers (45000) are converted.
     */
    public static function date(string $value, string $order = 'dmy'): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $value) && (float) $value > 20000 && (float) $value < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $value))->toDateString();
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $value, $match)) {
            return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]) : null;
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})$/', $value, $match)) {
            [$first, $second, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];
            $year = $year < 100 ? 2000 + $year : $year;
            [$day, $month] = $order === 'mdy' && $second <= 31 && $first <= 12 ? [$second, $first] : [$first, $second];
            if ($month > 12 && $day <= 12) {
                [$day, $month] = [$month, $day];
            }

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }
        if (preg_match('/[a-z]/i', $value)) {
            try {
                return CarbonImmutable::parse($value)->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /** A 24-hour H:i time from "14:30", "2:30 pm" or an Excel day fraction (0.604). */
    public static function time(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value >= 0 && (float) $value < 1) {
            $minutes = (int) round((float) $value * 1440);

            return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        }
        if (preg_match('/^(\d{1,2})[:.h](\d{2})(?::\d{2})?\s*(am|pm)?$/i', $value, $match)) {
            $hour = (int) $match[1];
            $meridiem = strtolower($match[3] ?? '');
            $hour = $meridiem === 'pm' && $hour < 12 ? $hour + 12 : ($meridiem === 'am' && $hour === 12 ? 0 : $hour);

            return $hour < 24 && (int) $match[2] < 60 ? sprintf('%02d:%02d', $hour, $match[2]) : null;
        }

        return null;
    }

    /** An ISO country code from a code ("ZW") or a name ("Zimbabwe"). */
    public static function country(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (isset(Lists::COUNTRIES[strtoupper($value)])) {
            return strtoupper($value);
        }
        $wanted = self::key($value);
        foreach (Lists::COUNTRIES as $code => $name) {
            if (self::key($name) === $wanted) {
                return $code;
            }
        }

        return null;
    }

    public static function currency(string $value): ?string
    {
        $value = strtoupper(trim($value));

        return isset(Lists::CURRENCIES[$value]) ? $value : null;
    }

    /**
     * The option key whose key or label matches the value, or whose label alone starts with it ("F" for Female).
     *
     * @param  array<string, string>  $options
     */
    public static function option(string $value, array $options): ?string
    {
        $wanted = self::key($value);
        foreach ($options as $key => $label) {
            if (self::key((string) $key) === $wanted || self::key($label) === $wanted) {
                return (string) $key;
            }
        }

        $prefixed = $wanted === '' ? [] : array_keys(array_filter($options, fn (string $label) => str_starts_with(self::key($label), $wanted)));

        return count($prefixed) === 1 ? (string) $prefixed[0] : null;
    }
}
