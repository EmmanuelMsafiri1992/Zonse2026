<?php

namespace App\Support;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Per-module pricing for the marketplace. A plan covers either a fixed list of
 * apps or a number of "slots" (plans.limits.modules); every enabled app beyond
 * that is an add-on billed at the module's own price on top of the plan.
 *
 * Allocation is deterministic: apps already covered keep their slot, existing
 * add-ons are promoted first when a slot frees up, and newly requested apps
 * come last. An add-on's price is locked the moment it becomes an add-on.
 */
class ModuleBilling
{
    /**
     * Work out which enabled (plus optionally requested) apps the plan covers.
     *
     * @param  array<int, string>  $requestedKeys  apps about to be enabled (dependencies are expanded)
     * @return array{included: array<int, string>, addons: array<string, array{monthly: float, yearly: float}>}
     */
    public function allocate(Workspace $workspace, ?Plan $plan, array $requestedKeys = []): array
    {
        $enabled = $this->enabledApps($workspace);
        $catalogue = Module::byKey();

        $candidates = $enabled->sortBy(fn (Module $m) => [(int) $m->pivot->is_addon, (string) $m->pivot->enabled_at, $m->pivot->id ?? 0])
            ->values()
            ->map(fn (Module $m) => ['module' => $m, 'pivot' => $m->pivot]);

        foreach (Module::expandDependencies($requestedKeys) as $key) {
            $module = $catalogue->get($key);
            if ($module && ! $module->is_core && ! $enabled->contains('key', $key)) {
                $candidates->push(['module' => $module, 'pivot' => null]);
            }
        }

        if (! $plan) {
            return ['included' => $candidates->pluck('module.key')->all(), 'addons' => []];
        }

        $planKeys = $plan->includes_all_modules ? null : array_flip($plan->modules()->pluck('key')->all());
        $allowance = $plan->moduleAllowance();
        $used = 0;
        $included = [];
        $addons = [];

        foreach ($candidates as ['module' => $module, 'pivot' => $pivot]) {
            $outsidePlan = $planKeys !== null && ! isset($planKeys[$module->key]);
            $overAllowance = $allowance !== null && $used >= $allowance;

            if (! $outsidePlan && ! $overAllowance) {
                $included[] = $module->key;
                $used++;

                continue;
            }

            $locked = $pivot && $pivot->is_addon && $pivot->addon_monthly !== null;
            $addons[$module->key] = [
                'monthly' => $locked ? (float) $pivot->addon_monthly : $module->priceFor('monthly'),
                'yearly' => $locked ? (float) $pivot->addon_yearly : $module->priceFor('yearly'),
            ];
        }

        return ['included' => $included, 'addons' => $addons];
    }

    /**
     * The add-ons that enabling these apps would create, with their prices.
     *
     * @param  array<int, string>  $keys
     * @return array<string, array{monthly: float, yearly: float}>
     */
    public function quoteEnable(Workspace $workspace, array $keys): array
    {
        $enabled = array_flip($workspace->enabledModuleKeys());

        return array_filter(
            $this->allocate($workspace, $workspace->plan(), $keys)['addons'],
            fn ($price, $key) => ! isset($enabled[$key]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Apps that would start being charged as add-ons after switching plan.
     *
     * @return array<string, array{monthly: float, yearly: float}>
     */
    public function quoteSwitch(Workspace $workspace, Plan $plan): array
    {
        $current = $this->enabledApps($workspace)->filter(fn (Module $m) => $m->pivot->is_addon)->pluck('key')->flip();

        return array_filter(
            $this->allocate($workspace, $plan)['addons'],
            fn ($price, $key) => ! $current->has($key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Store the allocation on the pivot rows and re-price the subscription. */
    public function reconcile(Workspace $workspace): void
    {
        $allocation = $this->allocate($workspace, $workspace->plan());

        foreach ($this->enabledApps($workspace) as $module) {
            $price = $allocation['addons'][$module->key] ?? null;
            $workspace->modules()->updateExistingPivot($module->id, [
                'is_addon' => $price !== null,
                'addon_monthly' => $price['monthly'] ?? null,
                'addon_yearly' => $price['yearly'] ?? null,
            ]);
        }

        $this->syncSubscriptionAmount($workspace);
    }

    /** @return Collection<int, Module> enabled add-on apps with their locked prices on the pivot */
    public function addons(Workspace $workspace): Collection
    {
        return $this->enabledApps($workspace)->filter(fn (Module $m) => $m->pivot->is_addon)->values();
    }

    public function addonTotal(Workspace $workspace, string $cycle): float
    {
        $column = $cycle === 'yearly' ? 'addon_yearly' : 'addon_monthly';

        return Money::round($this->addons($workspace)->sum(fn (Module $m) => (float) $m->pivot->{$column}));
    }

    /**
     * How many plan slots are in use, for the "4 of 6 apps" meter.
     *
     * @return array{used: int, allowance: int|null}
     */
    public function usage(Workspace $workspace): array
    {
        return [
            'used' => $this->enabledApps($workspace)->reject(fn (Module $m) => $m->pivot->is_addon)->count(),
            'allowance' => $workspace->plan()?->moduleAllowance(),
        ];
    }

    /** Subscription amount = plan price + add-ons, for the current billing cycle. */
    public function syncSubscriptionAmount(Workspace $workspace): void
    {
        $subscription = $workspace->subscription()->with('plan')->first();
        if (! $subscription?->plan || ! in_array($subscription->status, ['trialing', 'active'], true)) {
            return;
        }

        $cycle = $subscription->billing_cycle;
        $addons = $this->addons($workspace)->mapWithKeys(fn (Module $m) => [
            $m->key => (float) ($cycle === 'yearly' ? $m->pivot->addon_yearly : $m->pivot->addon_monthly),
        ]);

        $subscription->forceFill([
            'amount' => Money::round($subscription->plan->priceFor($cycle) + $addons->sum()),
            'meta' => array_merge($subscription->meta ?? [], ['addons' => $addons->all()]),
        ])->save();
        $workspace->unsetRelation('subscription');
    }

    /** @return Collection<int, Module> */
    protected function enabledApps(Workspace $workspace): Collection
    {
        return $workspace->modules()->where('modules.is_core', false)->withPivot('id')->get();
    }
}
