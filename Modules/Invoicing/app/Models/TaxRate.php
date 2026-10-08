<?php

namespace Modules\Invoicing\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Invoicing\Database\Factories\TaxRateFactory;

class TaxRate extends Model
{
    /** @use HasFactory<TaxRateFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'name', 'rate', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['rate' => 'float', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(function (TaxRate $rate) {
            if ($rate->is_default) {
                static::query()->where('id', '!=', $rate->id)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    protected static function newFactory(): TaxRateFactory
    {
        return TaxRateFactory::new();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function defaultRate(): float
    {
        return (float) (static::query()->active()->where('is_default', true)->value('rate') ?? 0);
    }
}
