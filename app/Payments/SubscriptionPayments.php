<?php

namespace App\Payments;

use App\Models\Checkout;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Support\ModuleBilling;
use Illuminate\Support\Facades\DB;

/**
 * Turns a paid plan checkout into subscription time. Paying for the plan a workspace is already
 * on adds a period from the later of today and the current period's end, so paying early never
 * loses days. Paying for a different plan closes the current subscription and starts the new one today.
 */
class SubscriptionPayments
{
    public function __construct(protected ModuleBilling $billing) {}

    /**
     * The USD price of a plan period for this workspace: the plan plus the add-on apps the plan would not cover.
     *
     * @return array{plan: float, addons: float, total: float}
     */
    public function quote(Workspace $workspace, Plan $plan, string $cycle): array
    {
        $addons = array_sum(array_column($this->billing->allocate($workspace, $plan)['addons'], $cycle === 'yearly' ? 'yearly' : 'monthly'));

        return [
            'plan' => $plan->priceFor($cycle),
            'addons' => round($addons, 2),
            'total' => round($plan->priceFor($cycle) + $addons, 2),
        ];
    }

    public function apply(Checkout $checkout): ?Subscription
    {
        $workspace = $checkout->workspace;
        $plan = Plan::query()->where('key', $checkout->subject['plan'] ?? '')->first();
        if (! $workspace || ! $plan) {
            return null;
        }
        $cycle = ($checkout->subject['cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';

        $subscription = DB::transaction(function () use ($workspace, $plan, $cycle, $checkout) {
            $current = $workspace->subscription()->first();
            $continuing = $current && $current->plan_id === $plan->id && in_array($current->status, ['trialing', 'active', 'past_due'], true);

            $start = now();
            if ($continuing && $current->status === 'active' && $current->current_period_end?->isFuture()) {
                $start = $current->current_period_end->copy();
            }
            $end = $cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth();

            $attributes = [
                'status' => 'active',
                'billing_cycle' => $cycle,
                'amount' => $checkout->base_amount,
                'currency' => $checkout->base_currency,
                'gateway' => $checkout->gateway,
                'gateway_ref' => $checkout->uuid,
                'trial_ends_at' => null,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'cancelled_at' => null,
                'ends_at' => null,
            ];

            if ($continuing) {
                $current->forceFill($attributes + ['meta' => array_merge($current->meta ?? [], ['last_checkout' => $checkout->uuid])])->save();
                $subscription = $current;
            } else {
                if ($current && in_array($current->status, ['trialing', 'active', 'past_due'], true)) {
                    $current->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'ends_at' => now()])->save();
                }
                $subscription = $workspace->subscriptions()->create($attributes + ['plan_id' => $plan->id, 'meta' => ['last_checkout' => $checkout->uuid]]);
            }

            $workspace->forceFill(['trial_ends_at' => null])->save();

            return $subscription;
        });

        $workspace->unsetRelation('subscription');
        $this->billing->reconcile($workspace);

        return $subscription->fresh();
    }
}
