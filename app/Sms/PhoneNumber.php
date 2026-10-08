<?php

namespace App\Sms;

/**
 * Turns the way people type phone numbers ("077 123 4567", "+263 77-123-4567", "00263771234567")
 * into the international form SMS providers need (+263771234567).
 */
class PhoneNumber
{
    /** Country dialling codes for the countries a workspace can choose. */
    public const DIAL_CODES = [
        'ZW' => '263', 'ZA' => '27', 'ZM' => '260', 'MW' => '265', 'MZ' => '258', 'BW' => '267', 'NA' => '264', 'LS' => '266',
        'SZ' => '268', 'AO' => '244', 'TZ' => '255', 'KE' => '254', 'UG' => '256', 'RW' => '250', 'BI' => '257', 'ET' => '251',
        'SO' => '252', 'SD' => '249', 'SS' => '211', 'EG' => '20', 'NG' => '234', 'GH' => '233', 'CM' => '237', 'CI' => '225',
        'SN' => '221', 'CD' => '243', 'CG' => '242', 'MA' => '212', 'DZ' => '213', 'TN' => '216', 'MU' => '230', 'MG' => '261',
        'SC' => '248', 'GB' => '44', 'IE' => '353', 'US' => '1', 'CA' => '1', 'AU' => '61', 'NZ' => '64', 'DE' => '49',
        'FR' => '33', 'ES' => '34', 'IT' => '39', 'NL' => '31', 'BE' => '32', 'PT' => '351', 'SE' => '46', 'NO' => '47',
        'DK' => '45', 'CH' => '41', 'PL' => '48', 'TR' => '90', 'AE' => '971', 'SA' => '966', 'QA' => '974', 'IN' => '91',
        'PK' => '92', 'BD' => '880', 'LK' => '94', 'CN' => '86', 'JP' => '81', 'SG' => '65', 'MY' => '60', 'ID' => '62',
        'PH' => '63', 'BR' => '55', 'MX' => '52', 'AR' => '54', 'CL' => '56', 'CO' => '57',
    ];

    /** The number in +E.164 form, or null when it cannot be a valid mobile number. */
    public static function normalize(?string $number, ?string $countryCode = null): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $number);
        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($digits, '+')) {
            $digits = '+'.str_replace('+', '', substr($digits, 1));
        } elseif (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } else {
            $dial = self::DIAL_CODES[strtoupper((string) $countryCode)] ?? null;
            if ($dial === null) {
                return null;
            }
            $digits = str_starts_with($digits, $dial) && strlen($digits) > 10
                ? '+'.$digits
                : '+'.$dial.ltrim($digits, '0');
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) === 1 ? $digits : null;
    }
}
