<?php

namespace App\Http\Controllers;

use App\Models\Checkout;
use App\Models\Plan;
use App\Payments\GatewayException;
use App\Payments\PlatformCheckout;
use App\Payments\SubscriptionPayments;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Paying for a plan. A workspace admin picks a plan, cycle and way to pay; the gateway's hosted
 * page takes the money and the plan period is applied when the gateway confirms it, whether the
 * person comes back to the site or the gateway's notification arrives first.
 */
class CheckoutController extends Controller
{
    public function __construct(protected PlatformCheckout $checkout, protected SubscriptionPayments $subscriptions) {}

    public function store(Request $request, WorkspaceContext $context): RedirectResponse
    {
        $workspace = $context->getOrFail();
        $gateways = array_keys($this->checkout->available());
        if ($this->checkout->manualEnabled()) {
            $gateways[] = PlatformCheckout::MANUAL;
        }

        $data = $request->validate([
            'plan' => ['required', 'exists:plans,key'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'gateway' => ['required', Rule::in($gateways)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $plan = Plan::active()->where('key', $data['plan'])->firstOrFail();
        if ($plan->isFree()) {
            return redirect()->route('settings.billing.index')->with('flash', ['type' => 'info', 'message' => 'The '.$plan->name.' plan is free. Choose it from the plans below; there is nothing to pay.']);
        }

        $quote = $this->subscriptions->quote($workspace, $plan, $data['billing_cycle']);
        $subject = ['plan' => $plan->key, 'cycle' => $data['billing_cycle'], 'plan_price' => $quote['plan'], 'addons' => $quote['addons']];

        try {
            $checkout = $this->checkout->begin($request->user(), $workspace, 'subscription', $subject, $quote['total'], $data['gateway'], $data['note'] ?? null);
            if ($checkout->isManual()) {
                Audit::log('settings', 'payment-sent', 'Said a bank transfer of '.$checkout->baseMoney().' was sent for the '.$plan->name.' plan', $checkout);

                return redirect()->route('settings.billing.index')->with('flash', [
                    'type' => 'info',
                    'message' => 'Thank you. Our team confirms transfers within one working day; your plan is extended as soon as it is confirmed.',
                ]);
            }

            $url = $this->checkout->start($checkout);
        } catch (GatewayException $e) {
            return redirect()->route('settings.billing.index')->with('flash', ['type' => 'danger', 'message' => 'The payment could not be started: '.$e->getMessage().' Try another way to pay.']);
        }

        return redirect()->away($url);
    }

    /** Back from the gateway: check with it, then show the billing page with the outcome. */
    public function complete(Request $request, string $checkout, WorkspaceContext $context): RedirectResponse
    {
        $checkout = Checkout::query()->where('uuid', $checkout)->firstOrFail();
        if ($checkout->workspace) {
            $context->set($checkout->workspace);
        }

        $status = $this->checkout->settle($checkout);
        if ($status === 'pending' && $request->boolean('cancelled')) {
            $checkout->markFailed('cancelled', 'You left the payment page.');
            $status = 'cancelled';
        }

        $flash = match ($status) {
            'paid' => ['type' => 'success', 'message' => 'Thank you. Your payment of '.$checkout->money().' has been received and your '.$checkout->description().' is active.'],
            'pending' => ['type' => 'info', 'message' => 'Your payment is being confirmed by '.ucfirst($checkout->gateway).'. Your plan is extended the moment it is.'],
            'cancelled' => ['type' => 'info', 'message' => 'Payment cancelled. Nothing was charged.'],
            default => ['type' => 'danger', 'message' => 'The payment did not go through. '.($checkout->failure_reason ?? '').' You can try again.'],
        };

        return redirect()->to($this->destination($checkout))->with('flash', $flash);
    }

    /** Server-to-server notification from a gateway. Always answers quickly; the gateway retries on errors. */
    public function webhook(Request $request, string $gateway): Response
    {
        $driver = $this->checkout->find($gateway) ?? abort(404);

        try {
            $notice = $driver->notification($request, $this->checkout->credentials($driver));
        } catch (GatewayException $e) {
            Log::warning('Rejected checkout notification', ['gateway' => $gateway, 'error' => $e->getMessage()]);

            return response('Invalid notification.', 400);
        }

        $checkout = $notice ? Checkout::query()->where('uuid', $notice['reference'])->where('gateway', $gateway)->first() : null;
        if ($checkout) {
            $this->checkout->settle($checkout, $notice['result']);
        }

        return response('OK');
    }

    protected function destination(Checkout $checkout): string
    {
        return $checkout->purpose === 'tokens' && app('router')->has('ai.index')
            ? route('ai.index')
            : route('settings.billing.index');
    }
}
