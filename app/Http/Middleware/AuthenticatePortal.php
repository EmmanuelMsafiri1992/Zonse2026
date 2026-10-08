<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Branding;
use App\Support\Portal;
use App\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a signed-in portal visitor (a contact, not a staff user) through to their workspace's
 * portal, and scopes every query to that workspace.
 */
class AuthenticatePortal
{
    public function __construct(protected WorkspaceContext $context, protected Portal $portal, protected Branding $branding) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Runs before route-model binding, so the slug is resolved here and handed back to the route.
        $workspace = $request->route('workspace');
        if (! $workspace instanceof Workspace) {
            $workspace = Workspace::where('slug', (string) $workspace)->firstOrFail();
            $request->route()->setParameter('workspace', $workspace);
        }

        $access = $this->portal->signedIn($workspace);
        if (! $access) {
            $this->portal->signOut($workspace);

            return redirect()->route('portal.login', $workspace);
        }

        $this->context->set($workspace);
        $request->attributes->set('portalAccess', $access);

        View::share([
            'portalWorkspace' => $workspace,
            'portalAccess' => $access,
            'portalSections' => $this->portal->sections($workspace),
            'brand' => $this->branding->for($workspace),
        ]);

        return $next($request);
    }
}
