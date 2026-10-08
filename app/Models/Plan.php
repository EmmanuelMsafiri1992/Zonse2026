<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Plan extends Model
{
    protected $fillable = [
        'key', 'name', 'tagline', 'description', 'price_monthly', 'price_yearly', 'currency',
        'trial_days', 'limits', 'includes_all_modules', 'is_featured', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'includes_all_modules' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
        ];
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_module');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }

    public function limit(string $key, mixed $default = null): mixed
    {
        return data_get($this->limits, $key, $default);
    }

    public function isFree(): bool
    {
        return (float) $this->price_monthly === 0.0 && (float) $this->price_yearly === 0.0;
    }

    public function includesModule(Module $module): bool
    {
        if ($this->includes_all_modules || $module->is_core) {
            return true;
        }

        return $this->modules()->where('modules.id', $module->id)->exists();
    }

    public function priceFor(string $cycle): float
    {
        return (float) ($cycle === 'yearly' ? $this->price_yearly : $this->price_monthly);
    }
}
