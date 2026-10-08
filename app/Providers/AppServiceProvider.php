<?php

namespace App\Providers;

use App\Listeners\RecordSecurityEvents;
use App\Models\User;
use App\Registries\MenuItem;
use App\Registries\MenuRegistry;
use App\Registries\ModuleRegistry;
use App\Registries\SearchRegistry;
use App\Registries\WidgetRegistry;
use App\Support\Health;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
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

        Event::subscribe(RecordSecurityEvents::class);

        // Copy the workspace onto its own indexed column so the audit log can filter by it.
        Activity::creating(function (Activity $activity) {
            $activity->workspace_id ??= $activity->properties['workspace_id'] ?? app(WorkspaceContext::class)->id();
        });

        $this->registerCoreMenu();

        // Make the active workspace available to every view as $workspace.
        View::composer('*', function ($view) {
            $view->with('workspace', app(WorkspaceContext::class)->get());
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
            ->add(MenuItem::make('Dashboard', 'dashboard', 'layout-dashboard')->order(0)->active('dashboard'));

        $menu->section('settings', 'Workspace', 900)
            ->add(MenuItem::make('Settings', 'settings.workspace.edit', 'settings')->order(10)
                ->active(['settings.workspace.*', 'settings.members.*', 'settings.branches.*', 'settings.audit.*', 'settings.data-export.*'])
                ->children([
                    MenuItem::make('General', 'settings.workspace.edit', 'building-2')->order(1)->active('settings.workspace.*'),
                    MenuItem::make('Team members', 'settings.members.index', 'users')->order(2)->active('settings.members.*'),
                    MenuItem::make('Branches', 'settings.branches.index', 'map-pin')->order(3)->active('settings.branches.*'),
                    MenuItem::make('Audit log', 'settings.audit.index', 'scroll-text')->order(4)->active(['settings.audit.*', 'settings.data-export.*']),
                ]))
            ->add(MenuItem::make('Apps & modules', 'settings.modules.index', 'layout-grid')->order(20)->active('settings.modules.*'))
            ->add(MenuItem::make('Plan & billing', 'settings.billing.index', 'credit-card')->order(30)->active('settings.billing.*'))
            ->add(MenuItem::make('Text messages', 'sms.index', 'message-square')->can('manage-workspace')->order(40)->active(['sms.*', 'settings.sms.*']));
    }
}
