<?php

namespace App\Registries;

use Closure;
use Illuminate\Support\Facades\Route;

class MenuItem
{
    /** @var array<int, MenuItem> */
    public array $children = [];

    /** Optional callback that decides whether the item is active (for routes shared by many items). */
    protected ?Closure $activeResolver = null;

    public function __construct(
        public string $label,
        public ?string $route = null,
        public array $routeParams = [],
        public string $icon = 'circle',
        public int $order = 100,
        public ?string $permission = null,
        public ?string $module = null,
        public ?string $badge = null,
        public ?string $badgeClass = null,
        public array $activePatterns = [],
        public ?string $url = null,
    ) {}

    public static function make(string $label, ?string $route = null, string $icon = 'circle'): static
    {
        return new static(label: $label, route: $route, icon: $icon);
    }

    public function order(int $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function can(?string $permission): static
    {
        $this->permission = $permission;

        return $this;
    }

    public function module(?string $module): static
    {
        $this->module = $module;

        return $this;
    }

    public function badge(?string $badge, string $class = ''): static
    {
        $this->badge = $badge;
        $this->badgeClass = $class;

        return $this;
    }

    public function active(array|string $patterns): static
    {
        $this->activePatterns = (array) $patterns;

        return $this;
    }

    public function activeUsing(Closure $resolver): static
    {
        $this->activeResolver = $resolver;

        return $this;
    }

    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function child(MenuItem $item): static
    {
        $this->children[] = $item;

        return $this;
    }

    public function children(array $items): static
    {
        foreach ($items as $item) {
            $this->child($item);
        }

        return $this;
    }

    public function href(): string
    {
        if ($this->url) {
            return $this->url;
        }
        if ($this->route && Route::has($this->route)) {
            return route($this->route, $this->routeParams);
        }

        return '#';
    }

    public function isActive(): bool
    {
        if ($this->activeResolver) {
            return (bool) call_user_func($this->activeResolver) || collect($this->children)->contains(fn (MenuItem $child) => $child->isActive());
        }

        $patterns = $this->activePatterns;
        if (! $patterns && $this->route) {
            // "contacts.index" -> "contacts.*"
            $parts = explode('.', $this->route);
            array_pop($parts);
            $patterns = $parts ? [implode('.', $parts).'.*'] : [$this->route];
        }
        if ($patterns && request()->routeIs(...$patterns)) {
            return true;
        }
        foreach ($this->children as $child) {
            if ($child->isActive()) {
                return true;
            }
        }

        return false;
    }

    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }
}
