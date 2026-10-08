<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\Payment;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'invoice_id' => $this->invoice_id,
            'contact_id' => $this->contact_id,
            'amount' => (float) $this->amount,
            'currency_code' => $this->currency_code,
            'paid_on' => $this->paid_on?->toDateString(),
            'method' => $this->method,
            'reference' => $this->reference,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
