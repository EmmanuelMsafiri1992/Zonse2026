<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Registries\MenuRegistry;
use App\Registries\WidgetRegistry;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    /** How many ready apps get a large tile and a summary widget on the dashboard. */
    public const PINNED_APPS = 4;

    public function __invoke(Request $request, WorkspaceContext $context, WidgetRegistry $widgets, MenuRegistry $menu)
    {
        $workspace = $context->getOrFail();
        $subscription = $workspace->subscription;
        $plan = $subscription?->plan;

        $enabled = $workspace->modules()->with('suite')->orderBy('modules.sort_order')->get();
        $ready = $enabled->filter(fn (Module $m) => $m->isAvailable());
        $pinned = $ready->take(self::PINNED_APPS);

        return view('dashboard.index', [
            'workspace' => $workspace,
            'subscription' => $subscription,
            'plan' => $plan,
            'enabledModules' => $enabled,
            'readyModules' => $ready,
            'pinnedModules' => $pinned,
            'otherModules' => $enabled->reject(fn (Module $m) => $pinned->contains('id', $m->id)),
            'memberCount' => $workspace->members()->count(),
            'branchCount' => $workspace->branches()->count(),
            'widgets' => $widgets->visible(),
            'widgetRegistry' => $widgets,
            'recentActivity' => Activity::query()
                ->where('workspace_id', $workspace->id)
                ->latest()->limit(8)->get(),
        ]);
    }
}
