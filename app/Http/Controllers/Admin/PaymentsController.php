<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Payments\PlatformCheckout;
use App\Support\Lists;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform payments for the people who run Zonseob: every checkout from every workspace, bank
 * transfers waiting to be confirmed, the exchange rates behind local-currency prices and the
 * instructions shown for transfers.
 */
class PaymentsController extends Controller
{
    public function __construct(protected PlatformCheckout $checkout) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $checkouts = Checkout::query()
            ->with(['user', 'workspace', 'confirmedBy'])
            ->when(in_array($status, array_keys(Checkout::STATUSES), true), fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(40)
            ->withQueryString();

        return view('admin.payments', [
            'checkouts' => $checkouts,
            'status' => $status,
            'pendingTransfers' => Checkout::query()->pending()->where('gateway', PlatformCheckout::MANUAL)->count(),
            'rates' => $this->checkout->rates(),
            'manualInstructions' => $this->checkout->manualInstructions(),
            'gateways' => $this->checkout->available(),
            'currencies' => Lists::CURRENCIES,
        ]);
    }

    public function confirm(Request $request, Checkout $checkout): RedirectResponse
    {
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:120']]);
        if (! $checkout->isManual() || ! $checkout->isPending()) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Only pending bank transfers can be confirmed by hand.']);
        }

        $this->checkout->confirmManually($checkout, $request->user(), $data['reference'] ?? null);

        return back()->with('flash', ['type' => 'success', 'message' => 'Confirmed. '.$checkout->description().' for '.($checkout->workspace?->name ?? $checkout->user?->name ?? 'the buyer').' is now active.']);
    }

    public function reject(Request $request, Checkout $checkout): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        if (! $checkout->isPending()) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Only pending payments can be rejected.']);
        }
        $checkout->markFailed('failed', $data['reason'] ?? 'The transfer was not received.');

        return back()->with('flash', ['type' => 'success', 'message' => 'Marked as not received.']);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rates' => ['nullable', 'array'],
            'rates.*.currency' => ['nullable', 'string', Rule::in(array_keys(Lists::CURRENCIES))],
            'rates.*.rate' => ['nullable', 'numeric', 'gt:0'],
            'manual_instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $rates = [];
        foreach ($data['rates'] ?? [] as $row) {
            if (($row['currency'] ?? '') !== '' && ($row['rate'] ?? '') !== '') {
                $rates[$row['currency']] = (float) $row['rate'];
            }
        }
        $this->checkout->setRates($rates);
        $this->checkout->setManualInstructions((string) ($data['manual_instructions'] ?? ''));

        return back()->with('flash', ['type' => 'success', 'message' => 'Payment settings saved.']);
    }
}
