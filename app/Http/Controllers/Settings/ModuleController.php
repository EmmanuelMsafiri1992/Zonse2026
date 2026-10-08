<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Suite;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;

/**
 * The in-app marketplace: every catalogue module grouped by suite, with
 * enable / disable toggles for what is included in the workspace's plan.
 */
class ModuleController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

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

        return view('settings.modules', [
            'workspace' => $workspace,
            'plan' => $plan,
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
        $plan = $workspace->plan();
        if ($plan && ! $plan->includesModule($module)) {
            return redirect()->route('settings.billing.index')
                ->with('flash', ['type' => 'warning', 'message' => "{$module->name} is not included in your {$plan->name} plan."]);
        }

        $workspace->enableModules([$module->key], $request->user());

        Audit::log('settings', 'module-enabled', "Turned on the {$module->name} app", $module);

        return back()->with('flash', ['type' => 'success', 'message' => "{$module->name} enabled."]);
    }

    public function disable(Module $module)
    {
        $workspace = $this->context->getOrFail();
        $workspace->disableModule($module->key);

        Audit::log('settings', 'module-disabled', "Turned off the {$module->name} app", $module);

        return back()->with('flash', ['type' => 'success', 'message' => "{$module->name} disabled."]);
    }
}
