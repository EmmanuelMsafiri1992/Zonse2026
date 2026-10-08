<?php

namespace Modules\Invoicing\Models;

use App\Support\Hardware\Barcodes;
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

    protected $fillable = ['workspace_id', 'type', 'name', 'sku', 'barcode', 'description', 'unit', 'price', 'cost', 'stock_qty', 'reorder_level', 'tax_rate_id', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'float', 'cost' => 'float', 'stock_qty' => 'float', 'reorder_level' => 'float', 'is_active' => 'boolean'];
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

        return $term === '' ? $query : $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%")->orWhere('barcode', $term));
    }

    /** Products with a stock quantity are counted down when sold; null means stock is not tracked. */
    public function tracksStock(): bool
    {
        return $this->type === 'product' && $this->stock_qty !== null;
    }

    public function isLowOnStock(): bool
    {
        return $this->tracksStock() && $this->stock_qty <= (float) ($this->reorder_level ?? 0);
    }

    /** Move stock by a signed quantity (negative when selling) without touching untracked items. */
    public function adjustStock(float $quantity): void
    {
        if ($this->tracksStock()) {
            $this->newQuery()->whereKey($this->getKey())->increment('stock_qty', $quantity);
            $this->stock_qty = (float) $this->stock_qty + $quantity;
        }
    }

    public function scopeLowOnStock(Builder $query): Builder
    {
        return $query->where('type', 'product')->whereNotNull('stock_qty')
            ->whereRaw('stock_qty <= coalesce(reorder_level, 0)');
    }

    /** @return array{id: int, name: string, description: ?string, unit: ?string, price: float, tax_rate: float} */
    public function toPickerRow(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'unit' => $this->unit,
            'price' => (float) $this->price, 'tax_rate' => (float) ($this->taxRate?->rate ?? 0),
        ];
    }

    /** The barcode as bars (EAN-13 or UPC-A), else null so labels fall back to a QR code. */
    public function barcodeSvg(int $moduleWidth = 2, int $height = 50): ?string
    {
        return $this->barcode ? Barcodes::ean13Svg($this->barcode, $moduleWidth, $height) : null;
    }
}
