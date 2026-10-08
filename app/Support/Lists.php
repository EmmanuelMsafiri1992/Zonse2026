<?php

namespace App\Support;

/**
 * Static option lists used by forms (countries, currencies, time zones, …).
 */
class Lists
{
    public const WORKSPACE_TYPES = [
        'company' => 'Company / business',
        'freelancer' => 'Freelancer / sole trader',
        'clinic' => 'Clinic / hospital / practice',
        'school' => 'School / college / training centre',
        'ngo' => 'NGO / non-profit / church',
        'farm' => 'Farm / agribusiness',
        'government' => 'Government / public body',
        'individual' => 'Personal use',
        'other' => 'Other',
    ];

    public const COUNTRIES = [
        'ZW' => 'Zimbabwe', 'ZA' => 'South Africa', 'ZM' => 'Zambia', 'MW' => 'Malawi', 'MZ' => 'Mozambique',
        'BW' => 'Botswana', 'NA' => 'Namibia', 'LS' => 'Lesotho', 'SZ' => 'Eswatini', 'AO' => 'Angola',
        'TZ' => 'Tanzania', 'KE' => 'Kenya', 'UG' => 'Uganda', 'RW' => 'Rwanda', 'BI' => 'Burundi',
        'ET' => 'Ethiopia', 'SO' => 'Somalia', 'SD' => 'Sudan', 'SS' => 'South Sudan', 'EG' => 'Egypt',
        'NG' => 'Nigeria', 'GH' => 'Ghana', 'CM' => 'Cameroon', 'CI' => "Côte d'Ivoire", 'SN' => 'Senegal',
        'CD' => 'DR Congo', 'CG' => 'Congo', 'MA' => 'Morocco', 'DZ' => 'Algeria', 'TN' => 'Tunisia',
        'MU' => 'Mauritius', 'MG' => 'Madagascar', 'SC' => 'Seychelles',
        'GB' => 'United Kingdom', 'IE' => 'Ireland', 'US' => 'United States', 'CA' => 'Canada', 'AU' => 'Australia',
        'NZ' => 'New Zealand', 'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands',
        'BE' => 'Belgium', 'PT' => 'Portugal', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'CH' => 'Switzerland',
        'PL' => 'Poland', 'TR' => 'Türkiye', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
        'IN' => 'India', 'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'LK' => 'Sri Lanka', 'CN' => 'China', 'JP' => 'Japan',
        'SG' => 'Singapore', 'MY' => 'Malaysia', 'ID' => 'Indonesia', 'PH' => 'Philippines', 'BR' => 'Brazil',
        'MX' => 'Mexico', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia',
    ];

    public const CURRENCIES = [
        'USD' => 'US Dollar', 'ZWG' => 'Zimbabwe Gold', 'ZAR' => 'South African Rand', 'ZMW' => 'Zambian Kwacha',
        'MWK' => 'Malawian Kwacha', 'MZN' => 'Mozambican Metical', 'BWP' => 'Botswana Pula', 'NAD' => 'Namibian Dollar',
        'KES' => 'Kenyan Shilling', 'TZS' => 'Tanzanian Shilling', 'UGX' => 'Ugandan Shilling', 'RWF' => 'Rwandan Franc',
        'ETB' => 'Ethiopian Birr', 'NGN' => 'Nigerian Naira', 'GHS' => 'Ghanaian Cedi', 'XOF' => 'West African CFA Franc',
        'XAF' => 'Central African CFA Franc', 'EGP' => 'Egyptian Pound', 'MAD' => 'Moroccan Dirham', 'MUR' => 'Mauritian Rupee',
        'EUR' => 'Euro', 'GBP' => 'British Pound', 'CAD' => 'Canadian Dollar', 'AUD' => 'Australian Dollar',
        'NZD' => 'New Zealand Dollar', 'CHF' => 'Swiss Franc', 'SEK' => 'Swedish Krona', 'NOK' => 'Norwegian Krone',
        'AED' => 'UAE Dirham', 'SAR' => 'Saudi Riyal', 'INR' => 'Indian Rupee', 'CNY' => 'Chinese Yuan',
        'JPY' => 'Japanese Yen', 'SGD' => 'Singapore Dollar', 'BRL' => 'Brazilian Real', 'MXN' => 'Mexican Peso',
    ];

    public const ROLES = [
        'owner' => 'Owner',
        'admin' => 'Administrator',
        'manager' => 'Manager',
        'member' => 'Member',
        'viewer' => 'Viewer (read only)',
    ];

    /** @return array<string, string> */
    public static function timezones(): array
    {
        $zones = \DateTimeZone::listIdentifiers();

        return array_combine($zones, array_map(fn ($z) => str_replace('_', ' ', $z), $zones));
    }

    public static function country(?string $code): ?string
    {
        return self::COUNTRIES[$code] ?? $code;
    }
}
