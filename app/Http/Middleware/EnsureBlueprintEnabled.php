<?php

namespace App\Http\Middleware;

use App\Blueprints\BlueprintRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('blueprint') on routes with a {blueprint} parameter.
 * Resolves the blueprint app from the URL and applies the same module /
 * plan gating as the static "module:<key>" middleware.
 */
class EnsureBlueprintEnabled
{
    public function __construct(protected BlueprintRegistry $blueprints, protected EnsureModuleEnabled $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->route('blueprint');

        if (! $this->blueprints->has($key)) {
            abort(404);
        }

        return $this->modules->handle($request, $next, $key);
    }
}
