<?php

namespace App\Support;

use App\Tenancy\WorkspaceContext;

/**
 * Formats amounts for display. Amounts are stored as decimals in the
 * workspace (or document) currency; nothing here converts between currencies.
 */
class Money
{
    public const SYMBOLS = [
        'USD' => '$', 'ZWG' => 'ZiG', 'ZAR' => 'R', 'ZMW' => 'K', 'MWK' => 'MK', 'MZN' => 'MT', 'BWP' => 'P',
        'NAD' => 'N$', 'KES' => 'KSh', 'TZS' => 'TSh', 'UGX' => 'USh', 'RWF' => 'FRw', 'ETB' => 'Br',
        'NGN' => '₦', 'GHS' => 'GH₵', 'EGP' => 'E£', 'MAD' => 'DH', 'MUR' => '₨', 'EUR' => '€', 'GBP' => '£',
        'CAD' => 'C$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'CHF' => 'CHF', 'SEK' => 'kr', 'NOK' => 'kr',
        'AED' => 'AED', 'SAR' => 'SR', 'INR' => '₹', 'CNY' => '¥', 'JPY' => '¥', 'SGD' => 'S$', 'BRL' => 'R$', 'MXN' => 'MX$',
    ];

    public static function format(float|int|string|null $amount, ?string $currency = null, int $decimals = 2): string
    {
        $currency ??= app(WorkspaceContext::class)->get()?->currency_code ?? 'USD';
        $amount = (float) ($amount ?? 0);
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';
        $sign = $amount < 0 ? '-' : '';

        return $sign.$symbol.number_format(abs($amount), $decimals);
    }

    public static function symbol(?string $currency = null): string
    {
        $currency ??= app(WorkspaceContext::class)->get()?->currency_code ?? 'USD';

        return self::SYMBOLS[$currency] ?? $currency;
    }

    public static function round(float|int|string|null $amount): float
    {
        return round((float) ($amount ?? 0), 2);
    }
}
