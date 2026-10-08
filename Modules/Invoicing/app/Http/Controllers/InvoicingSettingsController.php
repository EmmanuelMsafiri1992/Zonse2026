<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Sequence;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Documents\DocumentDesign;
use Modules\Invoicing\Http\Requests\TaxRateRequest;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\InvoiceLine;
use Modules\Invoicing\Models\TaxRate;
use Modules\Invoicing\Payments\PaymentGateway;
use Modules\Invoicing\Payments\PaymentGateways;

/**
 * Numbering, defaults, tax rates and online payment gateways. Reachable by workspace admins only
 * (the routes sit behind can:manage-workspace).
 */
class InvoicingSettingsController extends Controller
{
    public function edit(WorkspaceContext $context, PaymentGateways $gateways): View
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
            'design' => DocumentDesign::for($workspace),
            'taxRates' => TaxRate::query()->orderByDesc('is_default')->orderBy('rate')->get(),
            'gateways' => collect($gateways->all())->map(fn (PaymentGateway $gateway) => [
                'gateway' => $gateway,
                'enabled' => (bool) $workspace->setting('payments.'.$gateway->key().'.enabled'),
                'configured' => $gateways->isConfigured($workspace, $gateway),
                'values' => collect($gateways->credentials($workspace, $gateway))
                    ->map(fn (string $value, string $field) => $gateway->fields()[$field]['secret'] ? ($value === '' ? null : '••••'.substr($value, -4)) : $value)
                    ->all(),
            ])->all(),
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

    /** Switch gateways on or off and store their credentials. Secret fields left blank keep their saved value. */
    public function updateGateways(Request $request, WorkspaceContext $context, PaymentGateways $gateways): RedirectResponse
    {
        $workspace = $context->getOrFail();
        $rules = [];
        foreach ($gateways->all() as $key => $gateway) {
            $rules[$key.'.enabled'] = ['nullable', 'boolean'];
            foreach ($gateway->fields() as $field => $meta) {
                $rules[$key.'.'.$field] = ['nullable', 'string', 'max:255'];
            }
        }
        $rules['stripe.secret_key'][] = 'regex:/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/';
        $rules['stripe.webhook_secret'][] = 'regex:/^whsec_[A-Za-z0-9]+$/';
        $data = $request->validate($rules, [
            'stripe.secret_key.regex' => 'That does not look like a Stripe secret key (sk_live_… or sk_test_…).',
            'stripe.webhook_secret.regex' => 'That does not look like a Stripe webhook secret (whsec_…).',
        ]);

        foreach ($gateways->all() as $key => $gateway) {
            $gateways->configure($workspace, $gateway, (bool) ($data[$key]['enabled'] ?? false), $data[$key] ?? []);
            if ($request->boolean($key.'.forget_webhook_secret')) {
                $gateways->forget($workspace, $gateway, 'webhook_secret');
            }
        }

        $workspace->refresh();
        $missing = collect($gateways->all())
            ->filter(fn (PaymentGateway $gateway) => $workspace->setting('payments.'.$gateway->key().'.enabled') && ! $gateways->isConfigured($workspace, $gateway))
            ->map(fn (PaymentGateway $gateway) => $gateway->label());
        if ($missing->isNotEmpty()) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Saved, but '.$missing->implode(' and ').' needs its credentials before customers can pay with it.']);
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'Online payment settings saved.']);
    }

    public function updateDesign(Request $request, WorkspaceContext $context): RedirectResponse
    {
        $workspace = $context->getOrFail();
        $data = $request->validate(DocumentDesign::rules(), ['color.regex' => 'Pick a colour like #0073ea.']);

        DocumentDesign::save($workspace, $data);
        Audit::log('settings', 'document-design-updated', 'Changed the invoice and quote design to '.DocumentDesign::STYLES[$data['style']]['label'].' in '.strtolower($data['color']));

        return redirect()->to(route('settings.invoicing.edit').'#design')->with('flash', ['type' => 'success', 'message' => 'Document design saved.']);
    }

    /** A made-up invoice in the saved design, so the look can be checked before anything is sent. */
    public function previewDesign(WorkspaceContext $context): View
    {
        $workspace = $context->getOrFail();
        $currency = $workspace->currency_code ?? 'USD';

        $invoice = (new Invoice)->forceFill([
            'workspace_id' => $workspace->id, 'number' => 'SAMPLE-0001', 'status' => 'sent', 'currency_code' => $currency,
            'issue_date' => now(), 'due_date' => now()->addDays((int) $workspace->setting('invoicing.due_days', 14)),
            'subtotal' => 150, 'discount_amount' => 0, 'tax_total' => 15, 'total' => 165, 'amount_paid' => 0, 'balance' => 165,
            'notes' => $workspace->setting('invoicing.notes'), 'terms' => $workspace->setting('invoicing.terms'),
        ]);
        $invoice->setRelation('workspace', $workspace);
        $invoice->setRelation('branch', null);
        $invoice->setRelation('contact', (new Contact)->forceFill(['kind' => 'company', 'name' => 'Rudo Chikwanha', 'company_name' => 'Sample Customer Ltd', 'city' => 'Harare', 'email' => 'accounts@example.com']));
        $invoice->setRelation('payments', collect());
        $invoice->setRelation('lines', collect([
            (new InvoiceLine)->forceFill(['description' => 'Consultation', 'quantity' => 2, 'unit' => 'hrs', 'unit_price' => 50, 'tax_rate' => 10, 'line_total' => 100]),
            (new InvoiceLine)->forceFill(['description' => 'Materials', 'quantity' => 1, 'unit' => null, 'unit_price' => 50, 'tax_rate' => 10, 'line_total' => 50]),
        ]));

        return view('invoicing::invoices.print', ['invoice' => $invoice, 'document' => $invoice, 'kind' => 'invoice', 'preview' => true]);
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
