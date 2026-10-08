<?php

namespace Modules\Helpdesk\Providers;

use App\Registries\MenuItem;
use App\Registries\MenuRegistry;
use App\Registries\ModuleRegistry;
use App\Registries\SearchRegistry;
use App\Registries\WidgetRegistry;
use Illuminate\Support\Facades\Gate;
use Modules\Helpdesk\Models\Ticket;
use Modules\Helpdesk\Policies\TicketPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class HelpdeskServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Helpdesk';

    protected string $nameLower = 'helpdesk';

    /** @var list<class-string> */
    protected array $providers = [RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Ticket::class, TicketPolicy::class);

        $this->app->make(ModuleRegistry::class)->entry('helpdesk', 'tickets.index');

        $this->registerMenu();
        $this->registerWidgets();
        $this->registerSearch();
    }

    protected function registerMenu(): void
    {
        $this->app->make(MenuRegistry::class)->section('apps', 'Apps', 10)
            ->add(MenuItem::make('Helpdesk', 'tickets.index', 'life-buoy')->module('helpdesk')->order(50)
                ->active('tickets.*')
                ->children([
                    MenuItem::make('Tickets', 'tickets.index', 'inbox')->module('helpdesk')->order(1)->active(['tickets.index', 'tickets.show', 'tickets.edit']),
                    MenuItem::make('New ticket', 'tickets.create', 'plus-circle')->module('helpdesk')->order(2)->active('tickets.create'),
                ]));
    }

    protected function registerWidgets(): void
    {
        $this->app->make(WidgetRegistry::class)->register('helpdesk.queue', 'helpdesk::widgets.queue', [
            'module' => 'helpdesk', 'width' => 4, 'order' => 50, 'title' => 'Support queue',
            'data' => fn () => [
                'tickets' => Ticket::query()->with('contact')->active()->orderByUrgency()->limit(6)->get(),
                'openCount' => Ticket::query()->where('status', 'open')->count(),
                'unassignedCount' => Ticket::query()->active()->whereNull('assignee_id')->count(),
            ],
        ]);
    }

    protected function registerSearch(): void
    {
        $this->app->make(SearchRegistry::class)->register('tickets', 'Tickets', function (string $q, int $limit) {
            return Ticket::query()->with('contact')->search($q)->orderByDesc('last_activity_at')->limit($limit)->get()->map(fn (Ticket $ticket) => [
                'title' => $ticket->number.' · '.$ticket->subject,
                'sub' => $ticket->requesterName().' · '.$ticket->priorityLabel(),
                'url' => route('tickets.show', $ticket),
                'badge' => $ticket->status,
            ]);
        }, module: 'helpdesk', icon: 'life-buoy');
    }
}
