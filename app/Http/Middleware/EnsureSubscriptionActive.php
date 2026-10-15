<?php

namespace App\Http\Middleware;

use App\Payments\SubscriptionAccess;
use App\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a workspace whose plan has lapsed (trial over, period unpaid, grace period spent) out of
 * its apps until it pays. Billing, checkout, profile, help and sign-out stay open. Admins are sent
 * to the billing page; other members see a plain "paused" page that tells them who to ask.
 */
class EnsureSubscriptionActive
{
    /** Route names that stay open while a workspace is blocked. */
    public const OPEN_ROUTES = [
        'settings.billing.*', 'settings.data-export.*', 'checkout.*', 'ai.*', 'logout', 'profile.*', 'workspaces.*',
        'notifications.*', 'help.*', 'onboarding.*', 'invitations.*', 'sso.*', 'admin.*',
        'password.*', 'two-factor.*', 'user-profile-information.*', 'user-password.*', 'verification.*',
    ];

    public function __construct(protected WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspace = $this->context->get();
        if (! $user || ! $workspace || $request->routeIs(...self::OPEN_ROUTES)) {
            return $next($request);
        }

        $access = SubscriptionAccess::for($workspace);
        if (! $access->isBlocked()) {
            return $next($request);
        }

        if ($user->is_super_admin || $user->isAdminOf($workspace)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your plan has ended. Pay for a plan to carry on.'], 402);
            }

            return redirect()->route('settings.billing.index')->with('flash', [
                'type' => 'warning',
                'message' => 'Your plan has ended. Pay for a plan to carry on using your apps.',
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'This workspace is paused until its plan is paid.'], 402);
        }

        return response()->view('billing.paused', ['workspace' => $workspace, 'access' => $access], 402);
    }
}
