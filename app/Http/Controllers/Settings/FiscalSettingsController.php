<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\FiscalDocument;
use App\Support\Audit;
use App\Support\Fiscal\Authorities;
use App\Support\Fiscal\Fiscaliser;
use App\Support\Fiscal\FiscalSettings;
use App\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tax-authority reporting: which authority and taxpayer number invoices are reported under, and the
 * log of every fiscal document with its chain check and resend.
 */
class FiscalSettingsController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected Fiscaliser $fiscaliser) {}

    public function edit(): View
    {
        $workspace = $this->context->getOrFail();
        $settings = FiscalSettings::for($workspace);
        $stored = (array) $workspace->setting('fiscal', []);

        return view('settings.fiscal', [
            'workspace' => $workspace,
            'settings' => $settings,
            'switchedOn' => (bool) ($stored['enabled'] ?? false),
            'counts' => FiscalDocument::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'invoicingReady' => $workspace->hasModule('invoicing'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $enabled = $request->boolean('enabled');
        $authority = Authorities::get($request->input('authority'));

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'authority' => [Rule::requiredIf($enabled), 'nullable', Rule::in(array_keys(Authorities::ALL))],
            'taxpayer_id' => [Rule::requiredIf($enabled), 'nullable', 'string', 'max:40', function (string $attribute, mixed $value, Closure $fail) use ($authority) {
                if ($authority && $authority['tax_id_pattern'] && ! preg_match($authority['tax_id_pattern'], strtoupper(trim((string) $value)))) {
                    $fail('That does not look like a '.$authority['name'].' '.$authority['tax_id'].' ('.$authority['tax_id_hint'].').');
                }
            }],
            'device_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'mode' => ['required', Rule::in(array_keys(FiscalSettings::MODES))],
            'simulate_outage' => ['nullable', 'boolean'],
        ], [
            'authority.required' => 'Choose the tax authority to report to.',
            'taxpayer_id.required' => 'Enter your taxpayer number to switch reporting on.',
            'device_id.regex' => 'Use letters, numbers, dots, dashes and underscores only.',
        ]);

        FiscalSettings::save($workspace, [
            'enabled' => $enabled,
            'authority' => $data['authority'] ?? null,
            'taxpayer_id' => filled($data['taxpayer_id'] ?? null) ? strtoupper(trim($data['taxpayer_id'])) : null,
            'device_id' => filled($data['device_id'] ?? null) ? trim($data['device_id']) : null,
            'mode' => $data['mode'],
            'simulate_outage' => $request->boolean('simulate_outage'),
        ]);
        Audit::log('settings', 'fiscal-updated', $enabled ? 'Switched on tax-authority reporting ('.$authority['name'].')' : 'Updated tax-authority reporting (off)');

        return back()->with('flash', ['type' => 'success', 'message' => $enabled
            ? 'Saved. Invoices are now reported to '.$authority['name'].' as they are issued.'
            : 'Saved. Invoices are not being reported to a tax authority.']);
    }

    public function log(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => array_key_exists((string) $request->query('status'), FiscalDocument::STATUSES) ? $request->query('status') : null,
        ];

        $documents = FiscalDocument::query()->with('invoice')
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('fiscal_number', 'like', '%'.$filters['q'].'%')
                ->orWhere('verification_code', 'like', '%'.$filters['q'].'%')
                ->orWhereHas('invoice', fn ($invoice) => $invoice->where('number', 'like', '%'.$filters['q'].'%'))))
            ->orderByDesc('counter')->paginate(25)->withQueryString();

        return view('settings.fiscal-log', [
            'documents' => $documents,
            'filters' => $filters,
            'pending' => FiscalDocument::query()->where('status', 'pending')->count(),
        ]);
    }

    public function retry(): RedirectResponse
    {
        $results = $this->fiscaliser->retryPending($this->context->getOrFail());

        if ($results['signed'] + $results['waiting'] === 0) {
            return back()->with('flash', ['type' => 'info', 'message' => 'Nothing is waiting to be sent.']);
        }

        return back()->with('flash', $results['waiting'] === 0
            ? ['type' => 'success', 'message' => $results['signed'].' '.str('document')->plural($results['signed']).' accepted by the tax authority.']
            : ['type' => 'warning', 'message' => $results['signed'].' accepted; '.$results['waiting'].' still waiting. The tax authority could not be reached, so they will be sent again automatically.']);
    }

    public function verifyChain(): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $problems = $this->fiscaliser->verifyChain($workspace);
        Audit::log('settings', 'fiscal-chain-checked', $problems->isEmpty() ? 'Checked the fiscal document chain: intact' : 'Checked the fiscal document chain: '.$problems->count().' problems');

        if ($problems->isEmpty()) {
            $count = FiscalDocument::query()->count();

            return back()->with('flash', ['type' => 'success', 'message' => 'All '.$count.' fiscal '.str('document')->plural($count).' check out: nothing has been changed or removed.']);
        }

        return back()->with('flash', ['type' => 'danger', 'message' => 'The fiscal chain is broken: '.$problems->take(3)->implode(' ').($problems->count() > 3 ? ' (+'.($problems->count() - 3).' more)' : '')]);
    }
}
