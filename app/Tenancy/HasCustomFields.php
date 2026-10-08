<?php

namespace App\Tenancy;

/**
 * A record that can carry the workspace's own extra fields, stored in its custom_fields column.
 *
 * @property array<string, mixed>|null $custom_fields
 */
trait HasCustomFields
{
    public function initializeHasCustomFields(): void
    {
        $this->mergeCasts(['custom_fields' => 'array']);
        if ($this->getFillable() !== []) {
            $this->mergeFillable(['custom_fields']);
        }
    }

    /** The stored value of one extra field. */
    public function customField(string $key): mixed
    {
        return ($this->custom_fields ?? [])[$key] ?? null;
    }
}
