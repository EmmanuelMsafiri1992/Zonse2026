<?php

namespace Modules\Contacts\Providers;

use App\Registries\MenuItem;
use App\Registries\MenuRegistry;
use App\Registries\ModuleRegistry;
use App\Registries\SearchRegistry;
use App\Registries\WidgetRegistry;
use Illuminate\Support\Facades\Gate;
use Modules\Contacts\Models\Contact;
use Modules\Contacts\Policies\ContactPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ContactsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Contacts';

    protected string $nameLower = 'contacts';

    /** @var list<class-string> */
    protected array $providers = [RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Contact::class, ContactPolicy::class);
        $this->app->make(ModuleRegistry::class)->entry('contacts', 'contacts.index');

        $this->app->make(MenuRegistry::class)
            ->section('apps', 'Apps', 10)
            ->add(MenuItem::make('Contacts', 'contacts.index', 'contact')->module('contacts')->order(10)->active('contacts.*'));

        $this->app->make(WidgetRegistry::class)->register('contacts.recent', 'contacts::widgets.recent', [
            'module' => 'contacts', 'width' => 4, 'order' => 90, 'title' => 'Recent contacts',
            'data' => fn () => ['contacts' => Contact::query()->active()->latest()->limit(5)->get()],
        ]);

        $this->app->make(SearchRegistry::class)->register('contacts', 'Contacts', function (string $q, int $limit) {
            return Contact::query()->search($q)->orderBy('name')->limit($limit)->get()->map(fn (Contact $c) => [
                'title' => $c->displayName(),
                'sub' => trim(($c->email ?? '').' '.($c->phone ?? '')),
                'url' => route('contacts.show', $c),
                'badge' => $c->type,
            ]);
        }, module: 'contacts', icon: 'contact');
    }
}
