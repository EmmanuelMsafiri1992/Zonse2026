<?php

namespace App\Support\Hardware;

use App\Models\Workspace;
use App\Support\Money;
use Modules\Invoicing\Models\Item;

/**
 * Barcodes for the counter: check digits, in-store codes for items without one, finding the
 * item a scanner just read (including weighing-scale labels) and drawing EAN-13 bars.
 *
 * Scale labels follow the common in-store layout: 2 prefix digits (21-29), a 5-digit item
 * number matched against the item's SKU, a 5-digit weight (grams) or price (cents), and a
 * check digit.
 */
class Barcodes
{
    /** In-store codes we generate start with 20, which no manufacturer or scale uses. */
    public const IN_STORE_PREFIX = '20';

    protected const LEFT_ODD = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    protected const LEFT_EVEN = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    protected const RIGHT = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    /** Odd/even pattern of the left half, chosen by the first digit. */
    protected const PARITY = ['OOOOOO', 'OOEOEE', 'OOEEOE', 'OOEEEO', 'OEOOEE', 'OEEOOE', 'OEEEOE', 'OEOEEO', 'OEOEOE', 'OEEOEO'];

    /** GS1 check digit for the digits that come before it (EAN-8, UPC-A, EAN-13, GTIN-14). */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    public static function isValidGtin(string $code): bool
    {
        return ctype_digit($code) && in_array(strlen($code), [8, 12, 13, 14], true)
            && self::checkDigit(substr($code, 0, -1)) === (int) substr($code, -1);
    }

    /** The next unused in-store EAN-13 for this workspace (deleted items keep theirs, so codes never repeat). */
    public static function nextInStoreCode(Workspace $workspace): string
    {
        $highest = Item::withTrashed()->forWorkspace($workspace)
            ->where('barcode', 'like', self::IN_STORE_PREFIX.'%')
            ->pluck('barcode')
            ->filter(fn (string $code) => strlen($code) === 13 && ctype_digit($code))
            ->map(fn (string $code) => (int) substr($code, 2, 10))
            ->max() ?? 0;

        $payload = self::IN_STORE_PREFIX.str_pad((string) ($highest + 1), 10, '0', STR_PAD_LEFT);

        return $payload.self::checkDigit($payload);
    }

    /**
     * The active item a scanned code points at and how many of it: one for a product barcode or
     * SKU, or the weight (or price ÷ unit price) printed on a scale label.
     *
     * @return array{item: Item, quantity: float}|null
     */
    public static function lookup(Workspace $workspace, string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 64) {
            return null;
        }

        $items = Item::query()->forWorkspace($workspace)->active()->with('taxRate');
        $item = (clone $items)->where('barcode', $code)->first()
            ?? (clone $items)->whereRaw('lower(sku) = ?', [mb_strtolower($code)])->first();
        if ($item) {
            return ['item' => $item, 'quantity' => 1.0];
        }

        $settings = HardwareSettings::for($workspace);
        if (strlen($code) !== 13 || ! self::isValidGtin($code) || ! in_array(substr($code, 0, 2), $settings['scale_prefixes'], true)) {
            return null;
        }

        $itemNumber = substr($code, 2, 5);
        $item = (clone $items)->whereIn('sku', array_unique([$itemNumber, ltrim($itemNumber, '0') ?: '0']))->first();
        if (! $item) {
            return null;
        }

        $value = (int) substr($code, 7, 5);
        $quantity = $settings['scale_mode'] === 'price'
            ? ($item->price > 0 ? round(Money::round($value / 100) / $item->price, 3) : 0.0)
            : round($value / 1000, 3);

        return $quantity > 0 ? ['item' => $item, 'quantity' => $quantity] : null;
    }

    /**
     * The 95 bar modules of an EAN-13 (a 12-digit UPC-A is drawn as EAN-13 with a leading 0).
     */
    public static function ean13Modules(string $code): ?string
    {
        if (strlen($code) === 12) {
            $code = '0'.$code;
        }
        if (strlen($code) !== 13 || ! self::isValidGtin($code)) {
            return null;
        }

        $digits = array_map('intval', str_split($code));
        $parity = self::PARITY[$digits[0]];
        $bars = '101';
        for ($i = 1; $i <= 6; $i++) {
            $bars .= $parity[$i - 1] === 'O' ? self::LEFT_ODD[$digits[$i]] : self::LEFT_EVEN[$digits[$i]];
        }
        $bars .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $bars .= self::RIGHT[$digits[$i]];
        }

        return $bars.'101';
    }

    /** An EAN-13 drawn as SVG with the digits underneath, or null when the code is not an EAN-13/UPC-A. */
    public static function ean13Svg(string $code, int $moduleWidth = 2, int $height = 60): ?string
    {
        $modules = self::ean13Modules($code);
        if ($modules === null) {
            return null;
        }

        $code = strlen($code) === 12 ? '0'.$code : $code;
        $quiet = 11 * $moduleWidth;
        $width = $quiet * 2 + 95 * $moduleWidth;
        $total = $height + 14;
        $rects = '';
        foreach (str_split($modules) as $index => $module) {
            if ($module === '1') {
                $guard = $index < 3 || ($index >= 45 && $index < 50) || $index >= 92;
                $rects .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $quiet + $index * $moduleWidth, $moduleWidth, $guard ? $height + 6 : $height);
            }
        }
        $text = sprintf(
            '<text x="%d" y="%d">%s</text><text x="%d" y="%d" text-anchor="middle">%s</text><text x="%d" y="%d" text-anchor="middle">%s</text>',
            $quiet - 4 * $moduleWidth, $total, $code[0],
            $quiet + 24 * $moduleWidth, $total, substr($code, 1, 6),
            $quiet + 71 * $moduleWidth, $total, substr($code, 7, 6),
        );

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-label="Barcode %s"><g fill="#000">%s</g><g font-family="monospace" font-size="%d" fill="#000">%s</g></svg>',
            $width, $total + 2, $width, $total + 2, e($code), $rects, 6 * $moduleWidth, $text,
        );
    }
}
