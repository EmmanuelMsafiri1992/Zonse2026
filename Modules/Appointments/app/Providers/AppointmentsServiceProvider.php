<?php

namespace Modules\Appointments\Providers;

use App\Registries\MenuItem;
use App\Registries\MenuRegistry;
use App\Registries\ModuleRegistry;
use App\Registries\SearchRegistry;
use App\Registries\WidgetRegistry;
use Illuminate\Support\Facades\Gate;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\Service;
use Modules\Appointments\Policies\AppointmentPolicy;
use Modules\Appointments\Policies\ServicePolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AppointmentsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Appointments';

    protected string $nameLower = 'appointments';

    /** @var list<class-string> */
    protected array $providers = [RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);

        $this->app->make(ModuleRegistry::class)->entry('appointments', 'appointments.calendar');

        $this->registerMenu();
        $this->registerWidgets();
        $this->registerSearch();
    }

    protected function registerMenu(): void
    {
        $menu = $this->app->make(MenuRegistry::class);

        $menu->section('apps', 'Apps', 10)
            ->add(MenuItem::make('Appointments', 'appointments.calendar', 'calendar-check')->module('appointments')->order(30)
                ->active(['appointments.*', 'services.*'])
                ->children([
                    MenuItem::make('Calendar', 'appointments.calendar', 'calendar-days')->module('appointments')->order(1)->active('appointments.calendar'),
                    MenuItem::make('All bookings', 'appointments.index', 'list')->module('appointments')->order(2)->active(['appointments.index', 'appointments.show', 'appointments.create', 'appointments.edit']),
                    MenuItem::make('Services', 'services.index', 'sparkles')->module('appointments')->order(3)->active('services.*'),
                ]));

        $menu->section('settings')
            ->add(MenuItem::make('Booking settings', 'settings.appointments.edit', 'calendar-cog')
                ->module('appointments')->can('manage-workspace')->order(16)->active('settings.appointments.*'));
    }

    protected function registerWidgets(): void
    {
        $this->app->make(WidgetRegistry::class)->register('appointments.today', 'appointments::widgets.today', [
            'module' => 'appointments', 'width' => 4, 'order' => 30, 'title' => 'Today',
            'data' => function () {
                $now = Appointment::localNow();

                return [
                    'today' => Appointment::query()->with(['contact', 'service', 'staff'])->active()->onDay($now)->orderBy('starts_at')->limit(6)->get(),
                    'todayCount' => Appointment::query()->active()->onDay($now)->count(),
                    'weekCount' => Appointment::query()->active()->between($now, $now->addDays(7))->count(),
                ];
            },
        ]);
    }

    protected function registerSearch(): void
    {
        $this->app->make(SearchRegistry::class)->register('appointments', 'Appointments', function (string $q, int $limit) {
            return Appointment::query()->with(['contact', 'service'])->search($q)->orderByDesc('starts_at')->limit($limit)->get()->map(fn (Appointment $a) => [
                'title' => $a->displayTitle().' · '.$a->contact?->displayName(),
                'sub' => $a->starts_at->format('D d M Y, H:i'),
                'url' => route('appointments.show', $a),
                'badge' => $a->status,
            ]);
        }, module: 'appointments', icon: 'calendar-check');
    }
}
