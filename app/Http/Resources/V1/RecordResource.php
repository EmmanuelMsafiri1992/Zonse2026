<?php

namespace App\Http\Resources\V1;

use App\Blueprints\BlueprintRegistry;
use App\Models\Record;
use App\Support\CustomFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Record */
class RecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $entity = $this->definition();
        $data = (array) $this->data;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'app' => $this->blueprint,
            'entity' => $this->entity,
            'title' => $this->title,
            'status' => $this->status,
            'amount' => $entity->hasAmount() && $this->amount !== null ? (float) $this->amount : null,
            'currency' => $this->currency,
            'occurs_on' => $this->occurs_on?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'contact_id' => $this->contact_id,
            'assignee_id' => $this->assignee_id,
            'branch_id' => $this->branch_id,
            'fields' => (object) collect($entity->fields)->mapWithKeys(fn ($field) => [$field->key => $data[$field->key] ?? null])->all(),
            'worked_out' => app(BlueprintRegistry::class)->computed($entity, $this->resource),
            'custom_fields' => (object) CustomFields::forApi($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
