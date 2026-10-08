<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the API key's workspace in the WorkspaceContext. The key acts as the person who made it,
 * so it stops working the moment they leave the workspace.
 */
class SetApiWorkspace
{
    public function __construct(protected WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $token instanceof ApiToken || ! $token->workspace_id) {
            abort(401, 'Send an API key as a Bearer token.');
        }

        $workspace = Workspace::find($token->workspace_id);
        if (! $workspace || ! $user->belongsToWorkspace($workspace)) {
            abort(403, 'This key\'s owner is no longer a member of the workspace.');
        }

        $membership = $user->membershipFor($workspace);
        $branch = $membership?->branch_id ? $workspace->branches()->find($membership->branch_id) : null;
        $this->context->set($workspace, $branch ?? $workspace->defaultBranch);
        setPermissionsTeamId($workspace->id);

        // Policies and form requests read the current workspace from the user; set it for this request only.
        $user->setAttribute('current_workspace_id', $workspace->id);
        $user->syncOriginalAttribute('current_workspace_id');

        return $next($request);
    }
}
