<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\Invoice;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'contact_id' => $this->contact_id,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency_code' => $this->currency_code,
            'reference' => $this->reference,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance' => $this->balance,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'tax_rate' => $line->tax_rate,
                'line_total' => $line->line_total,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
