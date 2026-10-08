<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Hardware\HardwareSettings;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Counter devices: receipt printer paper and extras, weighing-scale label barcodes and the
 * card terminal the till charges.
 */
class HardwareSettingsController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function edit(): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.hardware', [
            'workspace' => $workspace,
            'settings' => HardwareSettings::for($workspace),
            'tillReady' => $workspace->hasModule('pos'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'receipt_width' => ['required', 'integer', Rule::in(array_keys(HardwareSettings::PAPER_WIDTHS))],
            'receipt_header' => ['nullable', 'string', 'max:300'],
            'open_drawer' => ['nullable', 'boolean'],
            'receipt_qr' => ['nullable', 'boolean'],
            'scale_prefixes' => ['nullable', 'string', 'max:40', 'regex:/^\s*(2[1-9])(\s*,\s*2[1-9])*\s*$/'],
            'scale_mode' => ['required', Rule::in(array_keys(HardwareSettings::SCALE_MODES))],
            'card_terminal' => ['required', Rule::in(array_keys(HardwareSettings::CARD_TERMINALS))],
        ], ['scale_prefixes.regex' => 'Use two-digit prefixes from 21 to 29, separated by commas.']);

        HardwareSettings::save($workspace, [
            'receipt_width' => (int) $data['receipt_width'],
            'receipt_header' => trim((string) ($data['receipt_header'] ?? '')) ?: null,
            'open_drawer' => $request->boolean('open_drawer'),
            'receipt_qr' => $request->boolean('receipt_qr'),
            'scale_prefixes' => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($data['scale_prefixes'] ?? '')))))),
            'scale_mode' => $data['scale_mode'],
            'card_terminal' => $data['card_terminal'],
        ]);
        Audit::log('settings', 'hardware-updated', 'Updated hardware settings');

        return back()->with('flash', ['type' => 'success', 'message' => 'Hardware settings saved.']);
    }
}
