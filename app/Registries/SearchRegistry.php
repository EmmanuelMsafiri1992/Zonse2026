<?php

namespace App\Registries;

use App\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Support\Collection;

/**
 * Global search. Modules register a resolver that turns a query string into
 * result rows: ['title' => …, 'sub' => …, 'url' => …, 'icon' => …].
 *
 *   app(SearchRegistry::class)->register('contacts', 'Contacts', fn (string $q) => [...], module: 'contacts');
 */
class SearchRegistry
{
    /** @var array<string, array{label: string, resolver: Closure, module: ?string, icon: string}> */
    protected array $sources = [];

    public function register(string $key, string $label, Closure $resolver, ?string $module = null, string $icon = 'search'): static
    {
        $this->sources[$key] = ['label' => $label, 'resolver' => $resolver, 'module' => $module, 'icon' => $icon];

        return $this;
    }

    /**
     * @return Collection<string, array{label: string, icon: string, results: Collection<int, array<string, mixed>>}>
     */
    public function search(string $query, int $limitPerSource = 8): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }
        $context = app(WorkspaceContext::class);

        return collect($this->sources)
            ->filter(fn ($s) => $s['module'] === null || $context->hasModule($s['module']))
            ->map(fn ($s) => [
                'label' => $s['label'],
                'icon' => $s['icon'],
                'results' => collect(($s['resolver'])($query, $limitPerSource))->take($limitPerSource)->values(),
            ])
            ->filter(fn ($s) => $s['results']->isNotEmpty());
    }
}
