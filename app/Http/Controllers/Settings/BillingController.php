<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Plan;
use App\Support\Audit;
use App\Support\ModuleBilling;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plan selection and subscription state. Payment collection is deliberately
 * behind a "gateway" abstraction: today it is 'manual' (an admin confirms
 * payment); Paynow / Stripe / Paystack adapters plug in here later.
 */
class BillingController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected ModuleBilling $billing) {}

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
        ]);
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
