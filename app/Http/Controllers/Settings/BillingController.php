<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Models\Module;
use App\Models\Plan;
use App\Payments\PlatformCheckout;
use App\Payments\SubscriptionAccess;
use App\Payments\SubscriptionPayments;
use App\Support\Audit;
use App\Support\ModuleBilling;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Plan selection and subscription state. Switching plans here starts a trial or a manual
 * subscription; paying for it goes through CheckoutController (Paynow, Stripe or a bank transfer
 * an admin confirms), which activates the paid period.
 */
class BillingController extends Controller
{
    public function __construct(
        protected WorkspaceContext $context,
        protected ModuleBilling $billing,
        protected PlatformCheckout $checkout,
        protected SubscriptionPayments $payments,
    ) {}

    public function index()
    {
        $workspace = $this->context->getOrFail();
        $plans = Plan::active()->withCount('modules')->get();

        return view('settings.billing', [
            'workspace' => $workspace,
            'subscription' => $workspace->subscription,
            'plans' => $plans,
            'addons' => $this->billing->addons($workspace),
            'usage' => $this->billing->usage($workspace),
            // Apps each plan would start charging as add-ons, so the switch button can say so up front.
            'switchQuotes' => $plans->mapWithKeys(fn (Plan $p) => [$p->id => $this->billing->quoteSwitch($workspace, $p)]),
            'history' => $workspace->subscriptions()->with('plan')->latest()->limit(10)->get(),
            'quotes' => $this->quotes($workspace, $plans),
            'gateways' => $this->checkout->available(),
            'manualInstructions' => $this->checkout->manualInstructions(),
            'paynowCurrency' => $this->checkout->currencyFor('paynow'),
            'payments' => Checkout::query()->where('workspace_id', $workspace->id)->latest()->limit(10)->get(),
            'access' => SubscriptionAccess::for($workspace),
        ]);
    }

    /**
     * What each paid plan costs this workspace per cycle, in USD and in every local currency with a rate.
     *
     * @param  Collection<int, Plan>  $plans
     * @return array<string, array<string, array{plan_label: string, plan: string, addons: string, addons_raw: float, usd: string, local: array<string, string>}>>
     */
    protected function quotes($workspace, $plans): array
    {
        $quotes = [];
        foreach ($plans as $plan) {
            if ($plan->isFree()) {
                continue;
            }
            foreach (['monthly', 'yearly'] as $cycle) {
                $quote = $this->payments->quote($workspace, $plan, $cycle);
                $local = [];
                foreach ($this->checkout->localAmounts($quote['total']) as $currency => $amount) {
                    $local[$currency] = Money::format($amount, $currency);
                }
                $quotes[$plan->key][$cycle] = [
                    'plan_label' => $plan->name.' plan',
                    'plan' => Money::format($quote['plan'], PlatformCheckout::BASE_CURRENCY),
                    'addons' => Money::format($quote['addons'], PlatformCheckout::BASE_CURRENCY),
                    'addons_raw' => (float) $quote['addons'],
                    'usd' => Money::format($quote['total'], PlatformCheckout::BASE_CURRENCY),
                    'local' => $local,
                ];
            }
        }

        return $quotes;
    }

    public function subscribe(Request $request, Plan $plan)
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'keep_addons' => ['nullable', 'boolean'],
        ]);
        $cycle = $data['billing_cycle'];
        $current = $workspace->subscription;

        // Moving to a smaller plan must never start charging for apps silently.
        $newAddons = $this->billing->quoteSwitch($workspace, $plan);
        if ($newAddons && ! $request->boolean('keep_addons')) {
            $names = collect(array_keys($newAddons))->map(fn ($key) => Module::findByKey($key)?->name)->filter()->implode(', ');
            $total = Money::format(array_sum(array_column($newAddons, $cycle)), $plan->currency ?? 'USD');

            return back()->with('flash', [
                'type' => 'warning',
                'message' => "The {$plan->name} plan doesn't cover {$names}. Turn them off first, or keep them as add-ons for {$total} per ".($cycle === 'yearly' ? 'year' : 'month').'.',
            ]);
        }

        // Keep whatever trial time is left when moving between paid plans.
        $trialEnds = null;
        if (! $plan->isFree()) {
            $trialEnds = $current?->trial_ends_at?->isFuture()
                ? $current->trial_ends_at
                : ($current ? null : now()->addDays((int) ($plan->trial_days ?: 14)));
        }

        if ($current && in_array($current->status, ['trialing', 'active'], true)) {
            $current->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'ends_at' => now()])->save();
        }

        $workspace->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => $plan->isFree() || ! $trialEnds ? 'active' : 'trialing',
            'billing_cycle' => $cycle,
            'amount' => $plan->priceFor($cycle),
            'currency' => $plan->currency ?? 'USD',
            'gateway' => 'manual',
            'trial_ends_at' => $trialEnds,
            'current_period_start' => now(),
            'current_period_end' => $cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
        ]);
        $workspace->forceFill(['trial_ends_at' => $trialEnds])->save();
        $workspace->unsetRelation('subscription');
        $this->billing->reconcile($workspace);

        Audit::log('settings', 'plan-changed', "Switched to the {$plan->name} plan ({$cycle})", $plan);

        return back()->with('flash', ['type' => 'success', 'message' => "You're now on the {$plan->name} plan."]);
    }

    public function cancel()
    {
        $workspace = $this->context->getOrFail();
        $current = $workspace->subscription;

        if ($current && in_array($current->status, ['trialing', 'active'], true)) {
            $current->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => $current->current_period_end ?? now(),
            ])->save();
        }

        Audit::log('settings', 'plan-cancelled', 'Cancelled the subscription');

        return back()->with('flash', ['type' => 'warning', 'message' => 'Your subscription will end at the close of the current period.']);
    }
}
