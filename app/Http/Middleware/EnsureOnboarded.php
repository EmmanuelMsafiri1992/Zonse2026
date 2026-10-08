<?php

namespace App\Http\Middleware;

use App\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends workspaces that have not finished the setup wizard back to it.
 */
class EnsureOnboarded
{
    public function __construct(protected WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $this->context->get();

        if ($workspace && ! $workspace->isOnboarded() && ! $request->routeIs('onboarding.*')) {
            return redirect()->route('onboarding.step', $workspace->onboarding_step ?: 1);
        }

        return $next($request);
    }
}
