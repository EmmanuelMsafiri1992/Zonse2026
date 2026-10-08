<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Contacts\Models\Contact;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'kind' => $this->kind,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'tax_number' => $this->tax_number,
            'address' => $this->address,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'currency_code' => $this->currency_code,
            'tags' => $this->tags ?? [],
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'branch_id' => $this->branch_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
