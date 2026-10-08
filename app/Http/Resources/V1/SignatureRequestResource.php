<?php

namespace App\Http\Resources\V1;

use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SignatureRequest */
class SignatureRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'status' => $this->status,
            'signing_order' => $this->signing_order,
            'document_name' => $this->document_name,
            'document_sha256' => $this->document_hash,
            'signable_type' => $this->signable_type ? class_basename($this->signable_type) : null,
            'signable_id' => $this->signable_id,
            'signers' => $this->signers->map(fn (SignatureSigner $signer) => [
                'name' => $signer->name,
                'email' => $signer->email,
                'status' => $signer->status,
                'signed_at' => $signer->signed_at?->toIso8601String(),
                'declined_at' => $signer->declined_at?->toIso8601String(),
            ])->all(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
