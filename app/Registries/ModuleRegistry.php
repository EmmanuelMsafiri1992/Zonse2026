<?php

namespace App\Registries;

use App\Blueprints\BlueprintRegistry;
use App\Models\Module;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Bridges the code modules in Modules/ (nwidart) with the catalogue rows in
 * the `modules` table. A code module declares which catalogue keys it
 * provides in its module.json under "zonseo": { "provides": ["contacts"] }.
 */
class ModuleRegistry
{
    /**
     * Keys of catalogue modules that have code behind them.
     *
     * @return array<int, string>
     */
    public function installedKeys(): array
    {
        return Cache::remember('modules.installed_keys', 300, function () {
            $keys = [];
            foreach (File::glob(base_path('Modules/*/module.json')) as $file) {
                $json = json_decode(File::get($file), true) ?: [];
                $provides = $json['zonseo']['provides'] ?? [strtolower($json['alias'] ?? $json['name'] ?? '')];
                foreach ((array) $provides as $key) {
                    if ($key !== '') {
                        $keys[] = $key;
                    }
                }
            }

            // Blueprint apps (app/Blueprints/definitions) are installed too: they run on the generic record screens.
            $keys = array_merge($keys, app(BlueprintRegistry::class)->keys());

            return array_values(array_unique($keys));
        });
    }

    public function isInstalled(string $key): bool
    {
        return in_array($key, $this->installedKeys(), true);
    }

    /**
     * Mark catalogue rows installed/available according to what exists in Modules/.
     * Core (platform) modules are always considered installed. Blueprint apps also
     * lend their own icon to the catalogue card.
     */
    public function sync(): int
    {
        Cache::forget('modules.installed_keys');
        $installed = $this->installedKeys();
        $blueprints = app(BlueprintRegistry::class);
        $changed = 0;

        Module::query()->each(function (Module $module) use ($installed, $blueprints, &$changed) {
            $has = $module->is_core || in_array($module->key, $installed, true);
            $status = $has
                ? ($module->status === Module::STATUS_COMING_SOON ? Module::STATUS_AVAILABLE : $module->status)
                : Module::STATUS_COMING_SOON;
            $icon = $blueprints->get($module->key)?->icon ?? $module->icon;

            if ($module->is_installed !== $has || $module->status !== $status || $module->icon !== $icon) {
                $module->forceFill(['is_installed' => $has, 'status' => $status, 'icon' => $icon])->save();
                $changed++;
            }
        });

        return $changed;
    }

    /** Catalogue grouped by suite key, for the marketplace. */
    public function catalogue(): Collection
    {
        return Module::byKey()->groupBy(fn (Module $m) => $m->suite?->key ?? 'other');
    }

    /** @var array<string, array{0: string, 1: array<string, mixed>}> catalogue key => [named route, route params] that opens the app */
    protected array $entries = [];

    /** Code modules call this so dashboard cards and the marketplace can link straight into the app. */
    /** @param  array<string, mixed>  $params */
    public function entry(string $key, string $routeName, array $params = []): static
    {
        $this->entries[$key] = [$routeName, $params];

        return $this;
    }

    public function entryUrl(string $key): ?string
    {
        [$route, $params] = $this->entries[$key] ?? [null, []];

        return $route && Route::has($route) ? route($route, $params) : null;
    }
}
