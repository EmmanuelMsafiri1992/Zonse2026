<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Platform-wide pages (payments, exchange rates) for the people who run Zonseob, not workspace admins. */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_super_admin, 403);

        return $next($request);
    }
}
