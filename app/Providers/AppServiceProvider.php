<?php

namespace App\Providers;

use App\Listeners\DispatchBusinessEvents;
use App\Listeners\RecordSecurityEvents;
use App\Listeners\SendWorkspaceNotifications;
use App\Models\ApiToken;
use App\Models\User;
use App\Registries\MenuItem;
use App\Registries\MenuRegistry;
use App\Registries\ModuleRegistry;
use App\Registries\SearchRegistry;
use App\Registries\WidgetRegistry;
use App\Support\Branding;
use App\Support\Health;
use App\Tenancy\WorkspaceContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkspaceContext::class);
        $this->app->singleton(MenuRegistry::class);
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(SearchRegistry::class);
        $this->app->singleton(WidgetRegistry::class);
        $this->app->scoped(Branding::class);
    }

    public function boot(): void
    {
        $this->configureProduction();

        // Super admins can do anything; workspace owners/admins can do anything
        // inside their own workspace. Finer-grained permissions (spatie) apply
        // to everyone else.
        Gate::before(function (User $user, string $ability) {
            if ($user->is_super_admin) {
                return true;
            }
            $workspace = app(WorkspaceContext::class)->get();
            if ($workspace && $user->isAdminOf($workspace)) {
                return true;
            }

            return null;
        });

        Gate::define('manage-workspace', fn (User $user) => false);
        Gate::define('access-workspace', function (User $user) {
            $workspace = app(WorkspaceContext::class)->get();

            return $workspace !== null && $user->belongsToWorkspace($workspace);
        });
        Gate::define('capture-documents', function (User $user) {
            $workspace = app(WorkspaceContext::class)->get();

            return $workspace !== null && ! in_array($user->roleIn($workspace), [null, 'viewer'], true);
        });

        Gate::define('use-assistant', function (User $user) {
            $workspace = app(WorkspaceContext::class)->get();

            return $workspace !== null && $user->roleIn($workspace) !== null;
        });

        Event::subscribe(RecordSecurityEvents::class);
        Event::subscribe(SendWorkspaceNotifications::class);
        Event::subscribe(DispatchBusinessEvents::class);

        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by('key:'.($request->user()?->currentAccessToken()?->id ?? $request->ip())));
        RateLimiter::for('portal-link', fn (Request $request) => [
            Limit::perMinute(5)->by('portal-link:'.$request->ip().'|'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinute(20)->by('portal-link:'.$request->ip()),
        ]);
        RateLimiter::for('public-form', fn (Request $request) => Limit::perMinute(10)->by('public-form:'.$request->ip()));
        DatabaseNotification::creating(function (DatabaseNotification $notification) {
            $notification->workspace_id ??= $notification->data['workspace_id'] ?? null;
        });

        // Copy the workspace onto its own indexed column so the audit log can filter by it.
        Activity::creating(function (Activity $activity) {
            $activity->workspace_id ??= $activity->properties['workspace_id'] ?? app(WorkspaceContext::class)->id();
        });

        $this->registerCoreMenu();

        // Make the active workspace available to every view as $workspace.
        View::composer('*', function ($view) {
            $view->with('workspace', app(WorkspaceContext::class)->get());
        });
        View::composer(['layouts.app', 'layouts.auth', 'layouts.onboarding', 'partials.sidebar'], function ($view) {
            $view->with('brand', app(Branding::class)->current());
        });
    }

    /** Safety rails for the live site, plus the checks behind /up. */
    protected function configureProduction(): void
    {
        // migrate:fresh, db:wipe and friends refuse to run against the live database.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        URL::forceHttps(str_starts_with((string) config('app.url'), 'https://'));

        if ($proxies = config('zonseo.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        Event::listen(DiagnosingHealth::class, function () {
            $failing = collect($this->app->make(Health::class)->critical())->reject(fn (array $check) => $check['ok']);
            if ($failing->isNotEmpty()) {
                throw new RuntimeException('Health check failed: '.$failing->map(fn (array $check, string $name) => $name.' - '.$check['message'])->implode('; '));
            }
        });
    }

    protected function registerCoreMenu(): void
    {
        $menu = $this->app->make(MenuRegistry::class);

        $menu->section('main', 'Main', 0)
            ->add(MenuItem::make('Dashboard', 'dashboard', 'layout-dashboard')->order(0)->active('dashboard'))
            ->add(MenuItem::make('Approvals', 'approvals.index', 'circle-check')->order(1)->active('approvals.*'))
            ->add(MenuItem::make('E-signatures', 'signatures.index', 'file-signature')->order(2)->active('signatures.*'))
            ->add(MenuItem::make('Scan documents', 'captures.index', 'scan-text')->can('capture-documents')->order(3)->active(['captures.*', 'settings.ocr.*']))
            ->add(MenuItem::make('Assistant', 'assistant.index', 'sparkles')->can('use-assistant')->order(4)->active(['assistant.*', 'settings.assistant.*']))
            ->add(MenuItem::make('Phone access', 'ussd.index', 'smartphone')->can('access-workspace')->order(5)->active(['ussd.*', 'settings.ussd.*']));

        $menu->section('settings', 'Workspace', 900)
            ->add(MenuItem::make('Settings', 'settings.workspace.edit', 'settings')->order(10)
                ->active(['settings.workspace.*', 'settings.members.*', 'settings.branches.*', 'settings.audit.*', 'settings.data-export.*', 'settings.api.*', 'settings.webhooks.*', 'settings.automations.*', 'settings.custom-fields.*', 'settings.approval-rules.*', 'settings.branding.*', 'settings.partners.*', 'settings.portal.*', 'settings.public-page.*', 'settings.hardware.*', 'settings.fiscal.*'])
                ->children([
                    MenuItem::make('General', 'settings.workspace.edit', 'building-2')->order(1)->active('settings.workspace.*'),
                    MenuItem::make('Team members', 'settings.members.index', 'users')->order(2)->active('settings.members.*'),
                    MenuItem::make('Branches', 'settings.branches.index', 'map-pin')->order(3)->active('settings.branches.*'),
                    MenuItem::make('Audit log', 'settings.audit.index', 'scroll-text')->order(4)->active(['settings.audit.*', 'settings.data-export.*']),
                    MenuItem::make('Custom fields', 'settings.custom-fields.index', 'list-plus')->order(5)->active('settings.custom-fields.*'),
                    MenuItem::make('Approval rules', 'settings.approval-rules.index', 'shield-check')->order(6)->active('settings.approval-rules.*'),
                    MenuItem::make('Automations', 'settings.automations.index', 'zap')->order(7)->active('settings.automations.*'),
                    MenuItem::make('API & webhooks', 'settings.api.index', 'webhook')->order(8)->active(['settings.api.*', 'settings.webhooks.*']),
                    MenuItem::make('Branding', 'settings.branding.edit', 'palette')->order(9)->active('settings.branding.*'),
                    MenuItem::make('Partner program', 'settings.partners.index', 'handshake')->order(10)->active('settings.partners.*'),
                    MenuItem::make('Client portal', 'settings.portal.index', 'door-open')->order(11)->active('settings.portal.*'),
                    MenuItem::make('Public page', 'settings.public-page.edit', 'link')->order(12)->active('settings.public-page.*'),
                    MenuItem::make('Hardware', 'settings.hardware.edit', 'printer')->order(13)->active('settings.hardware.*'),
                    MenuItem::make('Fiscalisation', 'settings.fiscal.edit', 'landmark')->order(14)->active('settings.fiscal.*'),
                ]))
            ->add(MenuItem::make('Apps & modules', 'settings.modules.index', 'layout-grid')->order(20)->active('settings.modules.*'))
            ->add(MenuItem::make('Plan & billing', 'settings.billing.index', 'credit-card')->order(30)->active('settings.billing.*'))
            ->add(MenuItem::make('Text messages', 'sms.index', 'message-square')->can('manage-workspace')->order(40)->active(['sms.*', 'settings.sms.*']));
    }
}
