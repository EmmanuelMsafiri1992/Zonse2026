<?php

namespace App\Support\Fiscal;

use App\Models\FiscalDocument;

/**
 * The tax authorities whose e-invoicing / fiscal device schemes the platform reports to. Each one
 * names the taxpayer number it uses, and, where the format is fixed, the pattern that number follows.
 */
class Authorities
{
    /** @var array<string, array{name: string, scheme: string, country: string, tax_id: string, tax_id_pattern: ?string, tax_id_hint: ?string, device: string}> */
    public const ALL = [
        'zimra' => ['name' => 'ZIMRA', 'scheme' => 'Fiscal Device Management System (FDMS)', 'country' => 'ZW', 'tax_id' => 'TIN', 'tax_id_pattern' => null, 'tax_id_hint' => null, 'device' => 'Fiscal device ID'],
        'kra' => ['name' => 'KRA', 'scheme' => 'eTIMS', 'country' => 'KE', 'tax_id' => 'KRA PIN', 'tax_id_pattern' => '/^[AP]\d{9}[A-Z]$/', 'tax_id_hint' => 'like P051234567X', 'device' => 'Control unit serial'],
        'zra' => ['name' => 'ZRA', 'scheme' => 'Smart Invoice', 'country' => 'ZM', 'tax_id' => 'TPIN', 'tax_id_pattern' => '/^\d{10}$/', 'tax_id_hint' => '10 digits', 'device' => 'VSDC serial'],
        'mra' => ['name' => 'MRA', 'scheme' => 'Electronic Invoicing System (EIS)', 'country' => 'MW', 'tax_id' => 'TPIN', 'tax_id_pattern' => null, 'tax_id_hint' => null, 'device' => 'Terminal ID'],
        'zatca' => ['name' => 'ZATCA', 'scheme' => 'Fatoora e-invoicing', 'country' => 'SA', 'tax_id' => 'VAT number', 'tax_id_pattern' => '/^3\d{13}3$/', 'tax_id_hint' => '15 digits starting and ending in 3', 'device' => 'EGS unit ID'],
        'firs' => ['name' => 'FIRS', 'scheme' => 'Merchant-Buyer Solution e-invoicing', 'country' => 'NG', 'tax_id' => 'TIN', 'tax_id_pattern' => null, 'tax_id_hint' => null, 'device' => 'Business ID'],
        'sars' => ['name' => 'SARS', 'scheme' => 'VAT e-invoicing', 'country' => 'ZA', 'tax_id' => 'VAT number', 'tax_id_pattern' => '/^4\d{9}$/', 'tax_id_hint' => '10 digits starting with 4', 'device' => 'Branch or device ID'],
    ];

    /** @return array{name: string, scheme: string, country: string, tax_id: string, tax_id_pattern: ?string, tax_id_hint: ?string, device: string}|null */
    public static function get(?string $key): ?array
    {
        return self::ALL[$key] ?? null;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_map(fn (array $authority) => $authority['name'].' · '.$authority['scheme'], self::ALL);
    }

    public static function qrData(FiscalDocument $document): string
    {
        return $document->authority === 'zatca' ? self::zatcaTlv($document) : $document->verifyUrl();
    }

    /**
     * ZATCA's simplified-invoice QR: seller name, VAT number, timestamp, total with VAT and the VAT,
     * each as tag, length, value, the whole base64 encoded.
     */
    public static function zatcaTlv(FiscalDocument $document): string
    {
        $payload = $document->payload;
        $fields = [
            1 => (string) $payload['seller']['name'],
            2 => (string) $payload['seller']['tax_id'],
            3 => (string) $payload['issued_at'],
            4 => number_format((float) $payload['total'], 2, '.', ''),
            5 => number_format((float) $payload['tax_total'], 2, '.', ''),
        ];

        return base64_encode(implode('', array_map(fn (int $tag, string $value) => chr($tag).chr(strlen($value)).$value, array_keys($fields), $fields)));
    }
}
