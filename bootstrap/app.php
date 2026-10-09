<?php

use App\Http\Middleware\AuthenticatePortal;
use App\Http\Middleware\CapturePartnerReferral;
use App\Http\Middleware\EnsureBlueprintEnabled;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\HandleInstantNavigation;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetApiWorkspace;
use App\Http\Middleware\SetWorkspaceContext;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'workspace' => SetWorkspaceContext::class,
            'onboarded' => EnsureOnboarded::class,
            'module' => EnsureModuleEnabled::class,
            'blueprint' => EnsureBlueprintEnabled::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'api.workspace' => SetApiWorkspace::class,
            'abilities' => CheckAbilities::class,
            'portal' => AuthenticatePortal::class,
        ]);

        $middleware->web(append: [SecurityHeaders::class, CapturePartnerReferral::class, HandleInstantNavigation::class]);

        // Payment gateways post their notifications without a session; each one is verified in the controller.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // Resolve the tenant before route-model binding so scoped models never leak across workspaces.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            Authenticate::class,
            SetWorkspaceContext::class,
            SetApiWorkspace::class,
            AuthenticatePortal::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
