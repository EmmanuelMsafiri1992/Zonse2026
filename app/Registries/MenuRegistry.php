<?php

namespace App\Registries;

use App\Tenancy\WorkspaceContext;
use Illuminate\Support\Collection;

/**
 * Modules register their sidebar entries here from their service provider:
 *
 *   app(MenuRegistry::class)->section('operations', 'Operations', 30)
 *       ->add(MenuItem::make('Contacts', 'contacts.index', 'users')->module('contacts')->order(10));
 */
class MenuRegistry
{
    /** @var array<string, array{label: string, order: int, items: array<int, MenuItem>}> */
    protected array $sections = [];

    protected ?string $currentSection = null;

    public function section(string $key, ?string $label = null, int $order = 100): static
    {
        if (! isset($this->sections[$key])) {
            $this->sections[$key] = ['label' => $label ?? ucfirst($key), 'order' => $order, 'items' => []];
        } elseif ($label !== null) {
            $this->sections[$key]['label'] = $label;
            $this->sections[$key]['order'] = min($this->sections[$key]['order'], $order);
        }
        $this->currentSection = $key;

        return $this;
    }

    public function add(MenuItem $item, ?string $section = null): static
    {
        $section ??= $this->currentSection ?? 'general';
        if (! isset($this->sections[$section])) {
            $this->section($section);
        }
        $this->sections[$section]['items'][] = $item;

        return $this;
    }

    /**
     * Sections and items the current user may see, ordered.
     *
     * @return Collection<string, array{label: string, items: Collection<int, MenuItem>}>
     */
    public function visible(): Collection
    {
        $context = app(WorkspaceContext::class);
        $user = auth()->user();

        return collect($this->sections)
            ->sortBy('order')
            ->map(function ($section) use ($context, $user) {
                $items = collect($section['items'])
                    ->filter(fn (MenuItem $item) => $this->allowed($item, $context, $user))
                    ->each(function (MenuItem $item) use ($context, $user) {
                        $item->children = array_values(array_filter(
                            $item->children,
                            fn (MenuItem $c) => $this->allowed($c, $context, $user)
                        ));
                    })
                    ->sortBy('order')
                    ->values();

                return ['label' => $section['label'], 'items' => $items];
            })
            ->filter(fn ($section) => $section['items']->isNotEmpty());
    }

    protected function allowed(MenuItem $item, WorkspaceContext $context, $user): bool
    {
        if ($item->module && ! $context->hasModule($item->module)) {
            return false;
        }
        if ($item->permission && $user && ! $user->is_super_admin && ! $user->can($item->permission)) {
            return false;
        }

        return true;
    }
}
