<?php

namespace App\Registries;

use App\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Support\Collection;

/**
 * Dashboard widgets. Modules register a Blade view (or a closure returning
 * HTML) and the dashboard renders whichever ones the workspace may see.
 *
 *   app(WidgetRegistry::class)->register('invoicing.outstanding', 'invoicing::widgets.outstanding', [
 *       'module' => 'invoicing', 'width' => 4, 'order' => 20, 'title' => 'Outstanding invoices',
 *   ]);
 */
class WidgetRegistry
{
    /** @var array<string, array<string, mixed>> */
    protected array $widgets = [];

    public function register(string $key, string|Closure $view, array $options = []): static
    {
        $this->widgets[$key] = array_merge([
            'key' => $key,
            'view' => $view,
            'title' => null,
            'module' => null,
            'permission' => null,
            'width' => 4,      // bootstrap columns (1-12)
            'order' => 100,
            'data' => null,    // optional closure returning view data
        ], $options);

        return $this;
    }

    public function forget(string $key): static
    {
        unset($this->widgets[$key]);

        return $this;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function visible(): Collection
    {
        $context = app(WorkspaceContext::class);
        $user = auth()->user();

        return collect($this->widgets)
            ->filter(function ($w) use ($context, $user) {
                if ($w['module'] && ! $context->hasModule($w['module'])) {
                    return false;
                }
                if ($w['permission'] && $user && ! $user->is_super_admin && ! $user->can($w['permission'])) {
                    return false;
                }

                return true;
            })
            ->sortBy('order')
            ->values();
    }

    /** Render one widget to HTML. */
    public function render(array $widget): string
    {
        $data = is_callable($widget['data']) ? (array) call_user_func($widget['data']) : [];
        $data['widget'] = $widget;

        if ($widget['view'] instanceof Closure) {
            return (string) call_user_func($widget['view'], $data);
        }

        return view($widget['view'], $data)->render();
    }
}
