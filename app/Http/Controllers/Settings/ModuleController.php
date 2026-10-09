<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Suite;
use App\Support\Audit;
use App\Support\ModuleBilling;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;

/**
 * The in-app marketplace: every catalogue module grouped by suite, with
 * enable / disable toggles. Apps the plan doesn't cover can be added as
 * paid add-ons at their own price (see ModuleBilling).
 */
class ModuleController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected ModuleBilling $billing) {}

    public function index(Request $request)
    {
        $workspace = $this->context->getOrFail();
        $plan = $workspace->plan();
        $enabled = $workspace->enabledModuleKeys();
        $planKeys = $plan && ! $plan->includes_all_modules ? $plan->modules()->pluck('key')->all() : null;

        $q = trim((string) $request->query('q'));
        $filter = $request->query('filter', 'all'); // all | enabled | available | coming_soon

        $suites = Suite::with(['modules' => fn ($m) => $m->orderBy('sort_order')])->orderBy('sort_order')->get()
            ->map(function (Suite $suite) use ($q, $filter, $enabled) {
                $suite->setRelation('modules', $suite->modules->filter(function (Module $m) use ($q, $filter, $enabled) {
                    if ($q !== '' && ! str_contains(strtolower($m->name.' '.$m->description.' '.implode(' ', $m->tags ?? [])), strtolower($q))) {
                        return false;
                    }

                    return match ($filter) {
                        'enabled' => in_array($m->key, $enabled, true) || $m->is_core,
                        'available' => $m->isAvailable(),
                        'coming_soon' => ! $m->isAvailable(),
                        default => true,
                    };
                })->values());

                return $suite;
            })
            ->filter(fn ($s) => $s->modules->isNotEmpty());

        $usage = $this->billing->usage($workspace);

        return view('settings.modules', [
            'workspace' => $workspace,
            'plan' => $plan,
            'cycle' => $workspace->subscription?->billing_cycle ?? 'monthly',
            'addonKeys' => $this->billing->addons($workspace)->pluck('key')->all(),
            'usage' => $usage,
            'slotsLeft' => $usage['allowance'] === null ? null : max(0, $usage['allowance'] - $usage['used']),
            'suites' => $suites,
            'enabled' => $enabled,
            'planKeys' => $planKeys,
            'q' => $q,
            'filter' => $filter,
            'counts' => [
                'enabled' => count($enabled),
                'total' => Module::where('is_core', false)->count(),
                'available' => Module::where('is_core', false)->installed()->count(),
            ],
        ]);
    }

    public function enable(Request $request, Module $module)
    {
        $workspace = $this->context->getOrFail();

        if ($module->is_core) {
            return back();
        }
        $cycle = $workspace->subscription?->billing_cycle ?? 'monthly';
        $addons = $this->billing->quoteEnable($workspace, [$module->key]);
        // A card button confirms the app's own price; extra paid dependencies need the full quote confirmed ("all").
        $confirmed = $request->input('confirm_addon') === 'all'
            || ($request->boolean('confirm_addon') && array_keys($addons) === [$module->key]);
        if ($addons && ! $confirmed) {
            $total = Money::format(array_sum(array_column($addons, $cycle)), $workspace->subscription?->currency ?? 'USD');
            $per = $cycle === 'yearly' ? 'year' : 'month';

            return back()->with('addon_quote', [
                'key' => $module->key,
                'message' => "{$module->name} isn't covered by your {$workspace->plan()?->name} plan. Add it for {$total} per {$per}, or upgrade your plan.",
                'apps' => collect(array_keys($addons))->map(fn ($key) => Module::findByKey($key)?->name)->filter()->values()->all(),
                'total' => $total,
                'per' => $per,
            ]);
        }

        $workspace->enableModules([$module->key], $request->user());
        $this->billing->reconcile($workspace);

        Audit::log('settings', 'module-enabled', $addons ? "Added the {$module->name} app as a paid add-on" : "Turned on the {$module->name} app", $module);

        return back()->with('flash', ['type' => 'success', 'message' => $addons ? "{$module->name} added as a paid add-on." : "{$module->name} enabled."]);
    }

    public function disable(Module $module)
    {
        $workspace = $this->context->getOrFail();
        $workspace->disableModule($module->key);
        $this->billing->reconcile($workspace);

        Audit::log('settings', 'module-disabled', "Turned off the {$module->name} app", $module);

        return back()->with('flash', ['type' => 'success', 'message' => "{$module->name} disabled."]);
    }
}
