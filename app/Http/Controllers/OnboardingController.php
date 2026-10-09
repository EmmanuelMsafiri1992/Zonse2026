<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Profession;
use App\Models\Suite;
use App\Models\Workspace;
use App\Support\BundleAdvisor;
use App\Support\Lists;
use App\Support\ModuleBilling;
use App\Support\Partners;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Four-step setup wizard. Step 1 creates the workspace; the remaining steps
 * refine it. workspaces.onboarding_step holds the next step to show (0 = done).
 */
class OnboardingController extends Controller
{
    public const STEPS = [
        1 => ['title' => 'Your workspace', 'icon' => 'building-2'],
        2 => ['title' => 'What do you do?', 'icon' => 'briefcase'],
        3 => ['title' => 'Pick your apps', 'icon' => 'layout-grid'],
        4 => ['title' => 'Choose a plan', 'icon' => 'credit-card'],
    ];

    public const DEFAULT_MODULES = ['contacts', 'invoicing', 'tasks', 'appointments'];

    public function __construct(protected WorkspaceContext $context, protected BundleAdvisor $advisor) {}

    public function start()
    {
        $workspace = $this->context->get();
        if ($workspace?->isOnboarded()) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('onboarding.step', $workspace?->onboarding_step ?: 1);
    }

    public function show(Request $request, int $step)
    {
        abort_unless(isset(self::STEPS[$step]), 404);
        $workspace = $this->context->get();

        if ($workspace?->isOnboarded()) {
            return redirect()->route('dashboard');
        }
        if (! $workspace && $step !== 1) {
            return redirect()->route('onboarding.step', 1);
        }
        if ($workspace && $step > $workspace->onboarding_step) {
            return redirect()->route('onboarding.step', $workspace->onboarding_step);
        }

        $data = ['step' => $step, 'steps' => self::STEPS, 'workspace' => $workspace];

        return match ($step) {
            1 => view('onboarding.workspace', $data + [
                'types' => Lists::WORKSPACE_TYPES,
                'countries' => Lists::COUNTRIES,
                'currencies' => Lists::CURRENCIES,
                'timezones' => Lists::timezones(),
            ]),
            2 => view('onboarding.profession', $data + [
                'groups' => Profession::orderBy('sort_order')->get()->groupBy('group'),
                'describe' => $describe = trim((string) $request->query('describe')),
                'matches' => $this->advisor->match($describe),
            ]),
            3 => view('onboarding.modules', $data + [
                'suites' => Suite::with(['modules' => fn ($q) => $q->where('is_core', false)->orderBy('sort_order')])
                    ->orderBy('sort_order')->get()->filter(fn ($s) => $s->modules->isNotEmpty()),
                'selected' => $this->preselectedModules($workspace),
                'recommended' => $workspace->profession?->recommendedModules()->pluck('key')->all() ?? [],
            ]),
            4 => $this->showPlans($workspace, $data),
        };
    }

    public function store(Request $request, int $step)
    {
        abort_unless(isset(self::STEPS[$step]), 404);

        return match ($step) {
            1 => $this->storeWorkspace($request),
            2 => $this->storeProfession($request),
            3 => $this->storeModules($request),
            4 => $this->storePlan($request),
        };
    }

    // ---- Step 1 -------------------------------------------------------------

    protected function storeWorkspace(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(Lists::WORKSPACE_TYPES))],
            'country_code' => ['required', Rule::in(array_keys(Lists::COUNTRIES))],
            'currency_code' => ['required', Rule::in(array_keys(Lists::CURRENCIES))],
            'timezone' => ['required', 'timezone:all'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);

        $user = $request->user();
        $workspace = $this->context->get();

        DB::transaction(function () use (&$workspace, $data, $user, $request) {
            if ($workspace) {
                $workspace->update($data);

                return;
            }

            $workspace = Workspace::create($data + [
                'owner_id' => $user->id,
                'email' => $data['email'] ?? $user->email,
                'onboarding_step' => 2,
                'locale' => 'en',
            ]);
            if ($partner = app(Partners::class)->attributionFor($request)) {
                $workspace->forceFill(['reseller_id' => $partner->id])->save();
            }
            $workspace->members()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
            $this->context->set($workspace);
            Branch::create(['workspace_id' => $workspace->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true, 'is_active' => true]);
            $user->switchWorkspace($workspace);
        });

        $this->advance($workspace, 2);

        return redirect()->route('onboarding.step', 2);
    }

    // ---- Step 2 -------------------------------------------------------------

    protected function storeProfession(Request $request)
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate(['profession_id' => ['nullable', 'exists:professions,id']]);

        $professionId = isset($data['profession_id']) ? (int) $data['profession_id'] : null;
        $currentId = $workspace->profession_id === null ? null : (int) $workspace->profession_id;
        if ($currentId !== $professionId) {
            // A different answer means a different starter bundle: drop the apps picked for the old one.
            $workspace->modules()->detach();
        }
        $workspace->update(['profession_id' => $professionId]);
        $this->advance($workspace, 3);

        return redirect()->route('onboarding.step', 3);
    }

    // ---- Step 3 -------------------------------------------------------------

    protected function storeModules(Request $request)
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', 'exists:modules,key'],
        ]);

        $keys = collect($data['modules'] ?? [])
            ->filter(fn ($k) => ($m = Module::findByKey($k)) && ! $m->is_core)
            ->values()->all();

        $workspace->modules()->detach();
        $workspace->enableModules($keys, $request->user());
        $this->advance($workspace, 4);

        return redirect()->route('onboarding.step', 4);
    }

    // ---- Step 4 -------------------------------------------------------------

    protected function storePlan(Request $request)
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'plan' => ['required', 'exists:plans,key'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);

        $plan = Plan::active()->where('key', $data['plan'])->firstOrFail();
        $cycle = $data['billing_cycle'];
        $trialDays = $plan->isFree() ? 0 : (int) ($plan->trial_days ?: 14);

        DB::transaction(function () use ($workspace, $plan, $cycle, $trialDays) {
            $workspace->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => $trialDays ? 'trialing' : 'active',
                'billing_cycle' => $cycle,
                'amount' => $plan->priceFor($cycle),
                'currency' => $plan->currency ?? 'USD',
                'gateway' => 'manual',
                'trial_ends_at' => $trialDays ? now()->addDays($trialDays) : null,
                'current_period_start' => now(),
                'current_period_end' => $cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
            ]);

            $workspace->forceFill([
                'onboarding_step' => 0,
                'onboarded_at' => now(),
                'trial_ends_at' => $trialDays ? now()->addDays($trialDays) : null,
            ])->save();
        });
        $workspace->unsetRelation('subscription');
        app(ModuleBilling::class)->reconcile($workspace);

        return redirect()->route('dashboard')->with('flash', [
            'type' => 'success',
            'message' => 'Welcome to Zonseo! Your workspace is ready.',
        ]);
    }

    protected function showPlans(Workspace $workspace, array $data)
    {
        $plans = Plan::active()->get();
        $recommendation = $this->advisor->recommendPlan($workspace, $plans);

        return view('onboarding.plan', $data + [
            'plans' => $plans,
            'moduleCount' => $workspace->modules()->count(),
            // Apps picked in step 3 that a plan doesn't cover become paid add-ons; show that on each card.
            'addonQuotes' => $plans->mapWithKeys(fn (Plan $p) => [$p->id => app(ModuleBilling::class)->quoteSwitch($workspace, $p)]),
            'recommendedPlan' => $recommendation['plan'],
        ]);
    }

    // ---- helpers ------------------------------------------------------------

    protected function advance(Workspace $workspace, int $next): void
    {
        if (! $workspace->isOnboarded() && $workspace->onboarding_step < $next) {
            $workspace->forceFill(['onboarding_step' => $next])->save();
        }
    }

    /** @return array<int, string> */
    protected function preselectedModules(Workspace $workspace): array
    {
        $enabled = $workspace->enabledModuleKeys();
        if ($enabled) {
            return $enabled;
        }
        if ($workspace->profession) {
            $keys = $workspace->profession->recommendedModules()->pluck('key')->all();
            if ($keys) {
                return $keys;
            }
        }

        return Module::expandDependencies(self::DEFAULT_MODULES);
    }
}
