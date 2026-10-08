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
    public function __invoke(Request $request, WorkspaceContext $context, WidgetRegistry $widgets, MenuRegistry $menu)
    {
        $workspace = $context->getOrFail();
        $subscription = $workspace->subscription;
        $plan = $subscription?->plan;

        $enabled = $workspace->modules()->with('suite')->orderBy('modules.sort_order')->get();

        return view('dashboard.index', [
            'workspace' => $workspace,
            'subscription' => $subscription,
            'plan' => $plan,
            'enabledModules' => $enabled,
            'readyModules' => $enabled->filter(fn (Module $m) => $m->isAvailable()),
            'memberCount' => $workspace->members()->count(),
            'branchCount' => $workspace->branches()->count(),
            'widgets' => $widgets->visible(),
            'widgetRegistry' => $widgets,
            'recentActivity' => Activity::query()
                ->where('properties->workspace_id', $workspace->id)
                ->latest()->limit(8)->get(),
        ]);
    }
}
