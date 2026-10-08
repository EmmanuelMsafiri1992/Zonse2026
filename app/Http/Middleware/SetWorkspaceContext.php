<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active workspace for the signed-in user and puts it in the
 * WorkspaceContext singleton. Falls back to the first workspace the user
 * belongs to; sends them to create one if they have none.
 */
class SetWorkspaceContext
{
    public function __construct(protected WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $workspace = $user->current_workspace_id
            ? Workspace::find($user->current_workspace_id)
            : null;

        if ($workspace && ! $user->belongsToWorkspace($workspace) && ! $user->is_super_admin) {
            $workspace = null;
        }

        if (! $workspace) {
            $workspace = $user->workspaces()->orderBy('workspace_user.created_at')->first();
            if ($workspace) {
                $user->switchWorkspace($workspace);
            }
        }

        if (! $workspace) {
            if ($request->routeIs('onboarding.*') || $request->routeIs('logout') || $request->routeIs('invitations.*')) {
                return $next($request);
            }

            return redirect()->route('onboarding.start');
        }

        $membership = $user->membershipFor($workspace);
        $branch = $membership?->branch_id ? $workspace->branches()->find($membership->branch_id) : null;
        $branch ??= $workspace->defaultBranch;

        $this->context->set($workspace, $branch);

        // Spatie permission: roles are scoped per workspace ("team").
        setPermissionsTeamId($workspace->id);

        if ($workspace->timezone) {
            config(['app.timezone_display' => $workspace->timezone]);
        }

        return $next($request);
    }
}
