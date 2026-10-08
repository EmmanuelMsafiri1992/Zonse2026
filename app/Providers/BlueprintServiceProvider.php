<?php

namespace App\Providers;

use App\Blueprints\Blueprint;
use App\Blueprints\BlueprintRegistry;
use App\Models\Record;
use App\Policies\RecordPolicy;
use App\Registries\MenuItem;
use App\Registries\MenuRegistry;
use App\Registries\ModuleRegistry;
use App\Registries\SearchRegistry;
use App\Registries\WidgetRegistry;
use App\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires every blueprint app into the platform: menu entries, dashboard
 * widgets, global search, and the catalogue "open app" links.
 */
class BlueprintServiceProvider extends ServiceProvider
{
    /** How many app widgets the dashboard shows. */
    protected const DASHBOARD_WIDGETS = 6;

    public function register(): void
    {
        $this->app->singleton(BlueprintRegistry::class);
    }

    public function boot(): void
    {
        Gate::policy(Record::class, RecordPolicy::class);

        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        $registry = $this->app->make(BlueprintRegistry::class);
        $modules = $this->app->make(ModuleRegistry::class);
        $menu = $this->app->make(MenuRegistry::class)->section('apps', 'Apps', 10);
        $widgets = $this->app->make(WidgetRegistry::class);

        $order = 100;
        foreach ($registry->all() as $blueprint) {
            $order++;
            $modules->entry($blueprint->key, 'apps.show', ['blueprint' => $blueprint->key]);
            $menu->add($this->menuItem($blueprint, $order));
            $widgets->register('apps.'.$blueprint->key, 'apps.widgets.summary', [
                'module' => $blueprint->key, 'width' => 4, 'order' => $order, 'title' => $blueprint->name,
                'when' => fn () => isset($this->dashboardSummary()[$blueprint->key]),
                'data' => fn () => $this->widgetData($blueprint),
            ]);
        }

        $this->registerSearch($registry);
    }

    protected function menuItem(Blueprint $blueprint, int $order): MenuItem
    {
        $item = MenuItem::make($blueprint->name, 'apps.show', $blueprint->icon)->module($blueprint->key)->order($order);
        $item->routeParams = ['blueprint' => $blueprint->key];
        $item->activeUsing(fn () => request()->routeIs('apps.show') && request()->route('blueprint') === $blueprint->key);

        $childOrder = 0;
        foreach ($blueprint->entities as $entity) {
            $childOrder++;
            $child = MenuItem::make($entity->plural, 'apps.records.index', $entity->icon)->module($blueprint->key)->order($childOrder);
            $child->routeParams = ['blueprint' => $blueprint->key, 'entity' => $entity->key];
            $child->activeUsing(fn () => request()->routeIs('apps.records.*')
                && request()->route('blueprint') === $blueprint->key
                && request()->route('entity') === $entity->key);
            $item->child($child);
        }

        return $item;
    }

    /** @return array<string, mixed> */
    protected function widgetData(Blueprint $blueprint): array
    {
        return [
            'app' => $blueprint,
            'counts' => $this->dashboardSummary()[$blueprint->key] ?? [],
            'recent' => Record::query()->ofBlueprint($blueprint->key)->orderByDesc('id')->limit(4)->get(),
        ];
    }

    /**
     * The apps that get a dashboard widget (the most recently active enabled apps, topped up
     * with the first enabled ones) mapped to their record counts per entity. Built with a few
     * queries and kept on the request, so a workspace with hundreds of apps stays fast.
     *
     * @return array<string, array<string, int>>
     */
    protected function dashboardSummary(): array
    {
        $request = request();
        if ($request->attributes->has('blueprint.dashboard')) {
            return $request->attributes->get('blueprint.dashboard');
        }

        $registry = $this->app->make(BlueprintRegistry::class);
        $workspace = $this->app->make(WorkspaceContext::class)->get();
        $enabled = $workspace ? array_values(array_intersect($registry->keys(), $workspace->enabledModuleKeys())) : [];

        $active = $enabled === [] ? [] : Record::query()->whereIn('blueprint', $enabled)
            ->selectRaw('blueprint, max(id) as latest')->groupBy('blueprint')
            ->orderByDesc('latest')->limit(self::DASHBOARD_WIDGETS)->pluck('blueprint')->all();
        $featured = array_slice(array_values(array_unique([...$active, ...$enabled])), 0, self::DASHBOARD_WIDGETS);

        $summary = array_fill_keys($featured, []);
        if ($featured !== []) {
            $rows = Record::query()->whereIn('blueprint', $featured)
                ->selectRaw('blueprint, entity, count(*) as total')->groupBy('blueprint', 'entity')->get();
            foreach ($rows as $row) {
                $summary[$row->blueprint][$row->entity] = (int) $row->total;
            }
        }

        $request->attributes->set('blueprint.dashboard', $summary);

        return $summary;
    }

    protected function registerSearch(BlueprintRegistry $registry): void
    {
        $this->app->make(SearchRegistry::class)->register('records', 'App records', function (string $q, int $limit) use ($registry) {
            $enabled = array_intersect($registry->keys(), app(WorkspaceContext::class)->getOrFail()->enabledModuleKeys());
            if ($enabled === []) {
                return collect();
            }

            return Record::query()->whereIn('blueprint', $enabled)->search($q)->orderByDesc('id')->limit($limit)->get()
                ->map(function (Record $record) use ($registry) {
                    $entity = $registry->entity($record->blueprint, $record->entity);

                    return [
                        'title' => $record->number.' · '.$record->title,
                        'sub' => ($registry->get($record->blueprint)?->name ?? $record->blueprint).' · '.($entity?->label ?? $record->entity),
                        'url' => $record->url(),
                        'badge' => $record->status,
                    ];
                });
        }, icon: 'layout-grid');
    }
}
