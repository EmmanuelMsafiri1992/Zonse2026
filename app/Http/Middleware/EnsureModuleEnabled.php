<?php

namespace App\Http\Middleware;

use App\Models\Module;
use App\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('module:invoicing')
 * Blocks access to a module's routes unless the workspace has enabled it
 * and its plan includes it.
 */
class EnsureModuleEnabled
{
    public function __construct(protected WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $workspace = $this->context->get();
        $module = Module::findByKey($key);

        if (! $workspace || ! $module) {
            abort(404);
        }

        if (! $module->is_core && ! $this->context->hasModule($key)) {
            abort_if($request->is('api/*'), 403, $module->name.' is not enabled for this workspace.');

            return redirect()->route('settings.modules.index')
                ->with('flash', ['type' => 'warning', 'message' => $module->name.' is not enabled for this workspace.']);
        }

        $plan = $workspace->plan();
        if ($plan && ! $plan->includesModule($module)) {
            abort_if($request->is('api/*'), 403, 'Your plan does not include '.$module->name.'.');

            return redirect()->route('settings.billing.index')
                ->with('flash', ['type' => 'warning', 'message' => 'Your plan does not include '.$module->name.'. Upgrade to use it.']);
        }

        return $next($request);
    }
}
