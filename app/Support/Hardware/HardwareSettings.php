<?php

namespace App\Support\Hardware;

use App\Models\Workspace;

/**
 * How the counter's devices are set up: receipt printer paper and extras, which barcode
 * prefixes the weighing scale prints on its labels, and whether a card terminal is linked.
 */
class HardwareSettings
{
    public const PAPER_WIDTHS = [58 => '58 mm (32 characters)', 80 => '80 mm (48 characters)'];

    public const SCALE_MODES = ['weight' => 'Weight in grams', 'price' => 'Price in cents'];

    public const CARD_TERMINALS = ['off' => 'Not linked: staff key the amount into the card machine', 'test' => 'Test terminal (no money moves)'];

    public const DEFAULTS = [
        'receipt_width' => 80,
        'receipt_header' => null,
        'open_drawer' => true,
        'receipt_qr' => true,
        'scale_prefixes' => ['21'],
        'scale_mode' => 'weight',
        'card_terminal' => 'off',
    ];

    /**
     * @return array{receipt_width: int, receipt_header: ?string, open_drawer: bool, receipt_qr: bool, scale_prefixes: list<string>, scale_mode: string, card_terminal: string}
     */
    public static function for(Workspace $workspace): array
    {
        $prefixes = $workspace->setting('hardware.scale_prefixes', self::DEFAULTS['scale_prefixes']);
        $width = (int) $workspace->setting('hardware.receipt_width', self::DEFAULTS['receipt_width']);
        $mode = (string) $workspace->setting('hardware.scale_mode', self::DEFAULTS['scale_mode']);
        $terminal = (string) $workspace->setting('hardware.card_terminal', self::DEFAULTS['card_terminal']);

        return [
            'receipt_width' => isset(self::PAPER_WIDTHS[$width]) ? $width : self::DEFAULTS['receipt_width'],
            'receipt_header' => $workspace->setting('hardware.receipt_header'),
            'open_drawer' => (bool) $workspace->setting('hardware.open_drawer', self::DEFAULTS['open_drawer']),
            'receipt_qr' => (bool) $workspace->setting('hardware.receipt_qr', self::DEFAULTS['receipt_qr']),
            'scale_prefixes' => array_values(array_map('strval', is_array($prefixes) ? $prefixes : self::DEFAULTS['scale_prefixes'])),
            'scale_mode' => isset(self::SCALE_MODES[$mode]) ? $mode : self::DEFAULTS['scale_mode'],
            'card_terminal' => isset(self::CARD_TERMINALS[$terminal]) ? $terminal : self::DEFAULTS['card_terminal'],
        ];
    }

    /** @param  array<string, mixed>  $values */
    public static function save(Workspace $workspace, array $values): void
    {
        $settings = $workspace->settings ?? [];
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            data_set($settings, 'hardware.'.$key, $value);
        }
        $workspace->forceFill(['settings' => $settings])->save();
    }

    /** Characters per printed line for the chosen paper. */
    public static function columns(int $width): int
    {
        return $width === 58 ? 32 : 48;
    }
}
