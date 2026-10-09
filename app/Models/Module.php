<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A catalogue entry. Every item in docs/01-MODULE-CATALOGUE.md is a row here.
 * Only rows with is_installed = true have code behind them (a folder in Modules/).
 */
class Module extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BETA = 'beta';

    public const STATUS_COMING_SOON = 'coming_soon';

    protected $fillable = [
        'suite_id', 'ref', 'key', 'name', 'description', 'icon', 'is_core', 'is_installed',
        'status', 'price_monthly', 'price_yearly', 'depends', 'tags', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_installed' => 'boolean',
            'depends' => 'array',
            'tags' => 'array',
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::$byKeyMemo = null);
        static::deleted(fn () => static::$byKeyMemo = null);
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(Suite::class);
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_module');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_module')->withTimestamps();
    }

    public function scopeInstalled(Builder $q): Builder
    {
        return $q->where('is_installed', true);
    }

    public function scopeSelectable(Builder $q): Builder
    {
        return $q->where('is_core', false);
    }

    /** @return Collection<string, Module> keyed by module key */
    public static function byKey()
    {
        // Per-request memo: the catalogue is small and Eloquent models do not serialise well into the DB cache.
        return static::$byKeyMemo ??= static::with('suite')->get()->keyBy('key');
    }

    protected static ?Collection $byKeyMemo = null;

    public static function findByKey(string $key): ?Module
    {
        return static::byKey()->get($key);
    }

    /**
     * Given module keys, return them plus everything they depend on (recursively).
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public static function expandDependencies(array $keys): array
    {
        $all = static::byKey();
        $result = [];
        $stack = array_values($keys);
        while ($stack) {
            $key = array_pop($stack);
            if (isset($result[$key]) || ! $all->has($key)) {
                continue;
            }
            $result[$key] = true;
            foreach ($all[$key]->depends ?? [] as $dep) {
                $stack[] = $dep;
            }
        }

        return array_keys($result);
    }

    public function priceFor(string $cycle): float
    {
        return (float) ($cycle === 'yearly' ? $this->price_yearly : $this->price_monthly);
    }

    public function isAvailable(): bool
    {
        return $this->is_installed && $this->status !== self::STATUS_COMING_SOON;
    }
}
