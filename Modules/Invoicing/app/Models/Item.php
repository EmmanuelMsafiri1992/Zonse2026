<?php

namespace Modules\Invoicing\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Invoicing\Database\Factories\ItemFactory;

class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    public const TYPES = ['service' => 'Service', 'product' => 'Product'];

    protected $fillable = ['workspace_id', 'type', 'name', 'sku', 'description', 'unit', 'price', 'cost', 'tax_rate_id', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'float', 'cost' => 'float', 'is_active' => 'boolean'];
    }

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"));
    }

    /** @return array{id: int, name: string, description: ?string, unit: ?string, price: float, tax_rate: float} */
    public function toPickerRow(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'unit' => $this->unit,
            'price' => (float) $this->price, 'tax_rate' => (float) ($this->taxRate?->rate ?? 0),
        ];
    }
}
