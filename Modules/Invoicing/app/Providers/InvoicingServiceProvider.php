<?php

namespace Modules\Invoicing\Providers;

use App\Registries\MenuItem;
use App\Registries\MenuRegistry;
use App\Registries\ModuleRegistry;
use App\Registries\SearchRegistry;
use App\Registries\WidgetRegistry;
use Illuminate\Support\Facades\Gate;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\Payment;
use Modules\Invoicing\Models\Quote;
use Modules\Invoicing\Policies\InvoicePolicy;
use Modules\Invoicing\Policies\ItemPolicy;
use Modules\Invoicing\Policies\PaymentPolicy;
use Modules\Invoicing\Policies\QuotePolicy;
use Modules\Invoicing\Sms\InvoiceTexts;
use Nwidart\Modules\Support\ModuleServiceProvider;

class InvoicingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Invoicing';

    protected string $nameLower = 'invoicing';

    /** @var list<class-string> */
    protected array $providers = [RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Item::class, ItemPolicy::class);

        Payment::created(fn (Payment $payment) => $this->app->make(InvoiceTexts::class)->paymentRecorded($payment));

        $this->app->make(ModuleRegistry::class)
            ->entry('invoicing', 'invoices.index')
            ->entry('quotes', 'quotes.index');

        $this->registerMenu();
        $this->registerWidgets();
        $this->registerSearch();
    }

    protected function registerMenu(): void
    {
        $menu = $this->app->make(MenuRegistry::class);

        $menu->section('apps', 'Apps', 10)
            ->add(MenuItem::make('Sales', 'invoices.index', 'receipt')->module('invoicing')->order(20)
                ->active(['invoices.*', 'quotes.*', 'payments.*', 'items.*'])
                ->children([
                    MenuItem::make('Invoices', 'invoices.index', 'file-text')->module('invoicing')->order(1)->active('invoices.*'),
                    MenuItem::make('Quotes', 'quotes.index', 'file-signature')->module('quotes')->order(2)->active('quotes.*'),
                    MenuItem::make('Payments', 'payments.index', 'banknote')->module('invoicing')->order(3)->active('payments.*'),
                    MenuItem::make('Products & services', 'items.index', 'package')->module('invoicing')->order(4)->active('items.*'),
                ]));

        $menu->section('settings')
            ->add(MenuItem::make('Sales settings', 'settings.invoicing.edit', 'sliders-horizontal')
                ->module('invoicing')->can('manage-workspace')->order(15)->active('settings.invoicing.*'));
    }

    protected function registerWidgets(): void
    {
        $this->app->make(WidgetRegistry::class)->register('invoicing.overview', 'invoicing::widgets.overview', [
            'module' => 'invoicing', 'width' => 8, 'order' => 20, 'title' => 'Sales',
            'data' => function () {
                Invoice::refreshOverdue();

                return [
                    'outstanding' => Invoice::query()->open()->sum('balance'),
                    'overdue' => Invoice::query()->where('status', 'overdue')->sum('balance'),
                    'collected' => Payment::query()->whereBetween('paid_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount'),
                    'invoices' => Invoice::query()->with('contact')->latest('issue_date')->latest('id')->limit(5)->get(),
                ];
            },
        ]);
    }

    protected function registerSearch(): void
    {
        $search = $this->app->make(SearchRegistry::class);

        $search->register('invoices', 'Invoices', function (string $q, int $limit) {
            return Invoice::query()->with('contact')->search($q)->latest('id')->limit($limit)->get()->map(fn (Invoice $i) => [
                'title' => $i->number.' · '.$i->contact?->displayName(),
                'sub' => $i->money($i->total).' · due '.$i->due_date?->format('d M Y'),
                'url' => route('invoices.show', $i),
                'badge' => $i->status,
            ]);
        }, module: 'invoicing', icon: 'file-text');

        $search->register('quotes', 'Quotes', function (string $q, int $limit) {
            return Quote::query()->with('contact')->search($q)->latest('id')->limit($limit)->get()->map(fn (Quote $quote) => [
                'title' => $quote->number.' · '.$quote->contact?->displayName(),
                'sub' => $quote->money($quote->total),
                'url' => route('quotes.show', $quote),
                'badge' => $quote->status,
            ]);
        }, module: 'quotes', icon: 'file-signature');
    }
}
