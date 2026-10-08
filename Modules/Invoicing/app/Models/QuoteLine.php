<?php

namespace Modules\Invoicing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteLine extends Model
{
    protected $fillable = ['quote_id', 'item_id', 'description', 'quantity', 'unit', 'unit_price', 'tax_rate', 'line_total', 'tax_amount', 'sort'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price' => 'float', 'tax_rate' => 'float', 'line_total' => 'float', 'tax_amount' => 'float'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
