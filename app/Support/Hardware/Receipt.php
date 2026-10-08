<?php

namespace App\Support\Hardware;

use App\Models\FiscalDocument;
use App\Models\Record;
use App\Models\Workspace;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * A till receipt for a point-of-sale sale: the same content as a narrow printable slip or as
 * ESC/POS bytes that a thermal receipt printer understands (text, QR, paper cut, cash drawer).
 */
class Receipt
{
    /**
     * @return array{business: string, header: list<string>, number: string, date: string, cashier: ?string, till: ?string, lines: list<array{description: string, quantity: string, unit_price: float, total: float}>, tax: float, total: float, tendered: float, change: float, method: string, approval_code: ?string, footer: string, qr: ?string, qr_label: string, fiscal: ?array{authority: string, number: string, code: string, test: bool}, currency: string, cash: bool}
     */
    public static function forSale(Record $sale, Workspace $workspace): array
    {
        $settings = HardwareSettings::for($workspace);
        $lines = array_map(fn (array $line) => [
            'description' => (string) $line['description'],
            'quantity' => rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.'),
            'unit_price' => (float) $line['unit_price'],
            'total' => (float) $line['total'],
        ], array_values((array) $sale->value('_lines')));
        $net = array_sum(array_map(fn (array $line) => Money::round((float) $line['quantity'] * (float) $line['unit_price']), (array) $sale->value('_lines')));
        $invoice = $sale->invoices()->latest('id')->first();
        $fiscal = $invoice ? FiscalDocument::query()->forWorkspace($workspace)->where('invoice_id', $invoice->id)->where('type', 'invoice')->first() : null;
        $qr = $fiscal ? $fiscal->qrData() : ($settings['receipt_qr'] ? $invoice?->publicUrl() : null);

        return [
            'business' => $workspace->name,
            'header' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $settings['receipt_header'])))),
            'number' => (string) $sale->number,
            'date' => ($sale->occurs_on ?? $sale->created_at)->format('d M Y').' '.$sale->created_at?->format('H:i'),
            'cashier' => $sale->assignee?->name,
            'till' => $sale->related('till')?->title,
            'lines' => $lines,
            'tax' => Money::round((float) $sale->amount - $net),
            'total' => (float) $sale->amount,
            'tendered' => (float) $sale->value('tendered'),
            'change' => (float) $sale->value('change'),
            'method' => ucfirst(str_replace('_', ' ', (string) $sale->value('payment_method'))),
            'approval_code' => $sale->value('_card_approval'),
            'footer' => (string) ($sale->related('till')?->value('receipt_footer') ?: 'Thank you for shopping with us.'),
            'qr' => $qr,
            'qr_label' => $fiscal ? 'Scan to verify with '.$fiscal->authorityName() : 'Scan for your invoice',
            'fiscal' => $fiscal ? [
                'authority' => $fiscal->authorityName(),
                'number' => $fiscal->fiscal_number,
                'code' => $fiscal->verification_code,
                'test' => ($fiscal->payload['mode'] ?? 'test') === 'test',
            ] : null,
            'currency' => (string) ($sale->currency ?: $workspace->currency_code),
            'cash' => $sale->value('payment_method') === 'cash',
        ];
    }

    /** ESC/POS commands for an 58 mm or 80 mm thermal printer. Text is plain ASCII so any code page prints it. */
    public static function escPos(array $receipt, int $paperWidth, bool $openDrawer): string
    {
        $columns = HardwareSettings::columns($paperWidth);
        $esc = "\x1B";
        $gs = "\x1D";
        $center = $esc.'a'."\x01";
        $left = $esc.'a'."\x00";
        $text = fn (string $value) => Str::ascii($value);
        $row = function (string $label, string $value) use ($columns, $text): string {
            $label = $text($label);
            $value = $text($value);
            $label = Str::limit($label, max(1, $columns - strlen($value) - 1), '');

            return $label.str_repeat(' ', max(1, $columns - strlen($label) - strlen($value))).$value."\n";
        };
        $amount = fn (float $value) => number_format($value, 2, '.', ',');

        $out = $esc.'@'.$center;
        $out .= $esc.'E'."\x01".$gs.'!'."\x11".wordwrap($text($receipt['business']), intdiv($columns, 2), "\n", true)."\n".$gs.'!'."\x00".$esc.'E'."\x00";
        foreach ($receipt['header'] as $line) {
            $out .= wordwrap($text($line), $columns, "\n", true)."\n";
        }
        $out .= "\n".$esc.'E'."\x01".'RECEIPT '.$text($receipt['number']).$esc.'E'."\x00"."\n".$left;
        $out .= $row('Date', $receipt['date']);
        if ($receipt['till']) {
            $out .= $row('Till', $receipt['till']);
        }
        if ($receipt['cashier']) {
            $out .= $row('Cashier', $receipt['cashier']);
        }
        $out .= str_repeat('-', $columns)."\n";
        foreach ($receipt['lines'] as $line) {
            $out .= wordwrap($text($line['description']), $columns, "\n", true)."\n";
            $out .= $row('  '.$line['quantity'].' x '.$amount($line['unit_price']), $amount($line['total']));
        }
        $out .= str_repeat('-', $columns)."\n";
        if ($receipt['tax'] > 0) {
            $out .= $row('Tax', $amount($receipt['tax']));
        }
        $out .= $esc.'E'."\x01".$row('TOTAL '.$receipt['currency'], $amount($receipt['total'])).$esc.'E'."\x00";
        $out .= $row('Paid by', $receipt['method']);
        if ($receipt['cash']) {
            $out .= $row('Tendered', $amount($receipt['tendered'])).$row('Change', $amount($receipt['change']));
        }
        if ($receipt['approval_code']) {
            $out .= $row('Card approval', $receipt['approval_code']);
        }
        if ($receipt['fiscal']) {
            $out .= str_repeat('-', $columns)."\n";
            $out .= $row('Fiscal no.', $receipt['fiscal']['number']).$row('Verify code', $receipt['fiscal']['code']);
            if ($receipt['fiscal']['test']) {
                $out .= $text($receipt['fiscal']['authority'].' test mode')."\n";
            }
        }
        $out .= "\n".$center.wordwrap($text($receipt['footer']), $columns, "\n", true)."\n";
        if ($receipt['qr']) {
            $out .= "\n".self::qr($receipt['qr']).$text($receipt['qr_label'])."\n";
        }
        $out .= $esc.'d'."\x04".$gs.'V'."\x42\x00";
        if ($openDrawer && $receipt['cash']) {
            $out .= $esc.'p'."\x00\x19\xFA";
        }

        return $out;
    }

    /** GS ( k: store and print a QR code (model 2, module size 6, error correction M). */
    protected static function qr(string $data): string
    {
        $store = strlen($data) + 3;
        $command = "\x1D(k";

        return $command."\x04\x00\x31\x41\x32\x00"
            .$command."\x03\x00\x31\x43\x06"
            .$command."\x03\x00\x31\x45\x31"
            .$command.chr($store % 256).chr(intdiv($store, 256))."\x31\x50\x30".$data
            .$command."\x03\x00\x31\x51\x30";
    }
}
