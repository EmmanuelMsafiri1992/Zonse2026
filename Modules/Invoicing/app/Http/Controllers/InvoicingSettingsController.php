<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Sequence;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Http\Requests\TaxRateRequest;
use Modules\Invoicing\Models\TaxRate;

/**
 * Numbering, defaults and tax rates. Reachable by workspace admins only
 * (the routes sit behind can:manage-workspace).
 */
class InvoicingSettingsController extends Controller
{
    public function edit(WorkspaceContext $context): View
    {
        $workspace = $context->getOrFail();

        return view('invoicing::settings', [
            'sequences' => [
                'invoice' => Sequence::current('invoice', 'INV-'),
                'quote' => Sequence::current('quote', 'QT-'),
                'payment' => Sequence::current('payment', 'PAY-'),
            ],
            'settings' => [
                'due_days' => (int) $workspace->setting('invoicing.due_days', 14),
                'quote_valid_days' => (int) $workspace->setting('invoicing.quote_valid_days', 30),
                'terms' => $workspace->setting('invoicing.terms'),
                'notes' => $workspace->setting('invoicing.notes'),
                'footer' => $workspace->setting('invoicing.footer'),
            ],
            'taxRates' => TaxRate::query()->orderByDesc('is_default')->orderBy('rate')->get(),
        ]);
    }

    public function update(Request $request, WorkspaceContext $context): RedirectResponse
    {
        $workspace = $context->getOrFail();

        $data = $request->validate([
            'invoice_prefix' => ['required', 'string', 'max:10'],
            'invoice_next' => ['required', 'integer', 'min:1', 'max:99999999'],
            'quote_prefix' => ['required', 'string', 'max:10'],
            'quote_next' => ['required', 'integer', 'min:1', 'max:99999999'],
            'payment_prefix' => ['required', 'string', 'max:10'],
            'payment_next' => ['required', 'integer', 'min:1', 'max:99999999'],
            'due_days' => ['required', 'integer', 'min:0', 'max:365'],
            'quote_valid_days' => ['required', 'integer', 'min:1', 'max:365'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'footer' => ['nullable', 'string', 'max:500'],
        ]);

        Sequence::configure('invoice', $data['invoice_prefix'], (int) $data['invoice_next']);
        Sequence::configure('quote', $data['quote_prefix'], (int) $data['quote_next']);
        Sequence::configure('payment', $data['payment_prefix'], (int) $data['payment_next']);

        foreach (['due_days', 'quote_valid_days', 'terms', 'notes', 'footer'] as $key) {
            $workspace->putSetting('invoicing.'.$key, $data[$key]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'Invoicing settings saved.']);
    }

    public function storeTaxRate(TaxRateRequest $request): RedirectResponse
    {
        $rate = TaxRate::create($request->payload());

        return back()->with('flash', ['type' => 'success', 'message' => $rate->name.' added.']);
    }

    public function updateTaxRate(TaxRateRequest $request, TaxRate $taxRate): RedirectResponse
    {
        $taxRate->update($request->payload());

        return back()->with('flash', ['type' => 'success', 'message' => $taxRate->name.' updated.']);
    }

    public function destroyTaxRate(TaxRate $taxRate): RedirectResponse
    {
        $taxRate->delete();

        return back()->with('flash', ['type' => 'success', 'message' => 'Tax rate removed. Existing documents keep the rate they were issued with.']);
    }
}
