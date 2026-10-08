<?php

namespace App\Support;

use App\Models\CustomField;
use App\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Validating, saving and showing the workspace's extra fields on its records.
 * Forms send them as custom[key]; the API takes and returns them as custom_fields.
 */
class CustomFields
{
    /**
     * A workspace's fields for one kind of record, in order: the current workspace unless one is named.
     *
     * @return Collection<int, CustomField>
     */
    public static function for(string $entity, ?int $workspaceId = null): Collection
    {
        $workspaceId ??= app(WorkspaceContext::class)->id();
        if (! $workspaceId) {
            return new Collection;
        }

        return CustomField::forWorkspace($workspaceId)->where('entity', $entity)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Rules for the request's custom[...] values. An update that sends none leaves them alone.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $entity, Request $request, ?Model $record = null): array
    {
        if ($record?->exists && ! $request->has('custom')) {
            return [];
        }

        return ['custom' => ['nullable', 'array']]
            + self::for($entity)->mapWithKeys(fn (CustomField $field) => ['custom.'.$field->key => $field->rules()])->all();
    }

    /** @return array<string, string> */
    public static function attributes(string $entity): array
    {
        return self::for($entity)->mapWithKeys(fn (CustomField $field) => ['custom.'.$field->key => $field->label])->all();
    }

    /**
     * The custom_fields value to save: stored values kept, sent ones cleaned and laid over them,
     * and values of fields that no longer exist dropped.
     *
     * @return array{custom_fields?: array<string, mixed>}
     */
    public static function payload(string $entity, Request $request, ?Model $record = null): array
    {
        if ($record?->exists && ! $request->has('custom')) {
            return [];
        }

        $sent = (array) $request->input('custom', []);
        $stored = (array) ($record?->getAttribute('custom_fields') ?? []);
        $values = [];
        foreach (self::for($entity) as $field) {
            $value = array_key_exists($field->key, $sent) ? $field->clean($sent[$field->key]) : ($stored[$field->key] ?? null);
            if ($value !== null) {
                $values[$field->key] = $value;
            }
        }

        return ['custom_fields' => $values];
    }

    /**
     * The record's filled-in fields, labelled and written out for reading.
     *
     * @return list<array{label: string, value: string, type: string}>
     */
    public static function details(Model $record): array
    {
        $entity = CustomField::entityFor($record);
        if (! $entity) {
            return [];
        }

        $values = (array) ($record->getAttribute('custom_fields') ?? []);

        return self::for($entity, $record->getAttribute('workspace_id'))
            ->map(fn (CustomField $field) => ['label' => $field->label, 'value' => $field->display($values[$field->key] ?? null), 'type' => $field->type])
            ->filter(fn (array $row) => $row['value'] !== null)
            ->values()->all();
    }

    /**
     * API input sends custom_fields as an object and may send only the ones that change;
     * the stored values are laid under them so required fields are still checked fairly.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function fromApi(array $input, ?Model $record = null): array
    {
        unset($input['custom']);
        if (is_array($input['custom_fields'] ?? null)) {
            $input['custom'] = array_merge((array) ($record?->getAttribute('custom_fields') ?? []), $input['custom_fields']);
        }
        unset($input['custom_fields']);

        return $input;
    }

    /**
     * Stored values keyed for API output, with every current field present.
     *
     * @return array<string, mixed>
     */
    public static function forApi(Model $record): array
    {
        $entity = CustomField::entityFor($record);
        $values = (array) ($record->getAttribute('custom_fields') ?? []);

        return $entity ? self::for($entity, $record->getAttribute('workspace_id'))->mapWithKeys(fn (CustomField $field) => [$field->key => $values[$field->key] ?? null])->all() : [];
    }
}
