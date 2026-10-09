<?php

namespace App\Http\Requests;

use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Entity;
use App\Models\CustomField;
use App\Models\Record;
use App\Support\CustomFields;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Validates a blueprint record from the entity definition in the URL. */
class RecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function entity(): Entity
    {
        return app(BlueprintRegistry::class)->entity((string) $this->route('blueprint'), (string) $this->route('entity'))
            ?? abort(404);
    }

    /** The workspace's extra-field kind for this entity, e.g. "clinic.patient". */
    public function customEntity(): string
    {
        $entity = $this->entity();

        return CustomField::appEntity($entity->blueprintKey, $entity->key);
    }

    /** The record being edited, or null when one is being added. */
    public function existing(): ?Record
    {
        $entity = $this->entity();

        return $this->route('record') ? Record::query()->ofEntity($entity->blueprintKey, $entity->key)->find($this->route('record')) : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $entity = $this->entity();
        $workspaceId = app(WorkspaceContext::class)->id();

        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys($entity->statuses))],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('workspace_id', $workspaceId)],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('workspace_id', $workspaceId)],
            'assignee_id' => ['nullable', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId)],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'occurs_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'data' => ['nullable', 'array'],
        ];

        foreach ($entity->fields as $field) {
            $fieldRules = $field->rules();
            if ($field->type === 'record') {
                $fieldRules[] = Rule::exists('records', 'id')
                    ->where('workspace_id', $workspaceId)
                    ->where('blueprint', $entity->blueprintKey)
                    ->where('entity', $field->relatedEntity)
                    ->whereNull('deleted_at');
            } elseif ($field->type === 'user') {
                $fieldRules[] = Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId);
            }
            $rules['data.'.$field->key] = $fieldRules;
        }

        return $rules + CustomFields::rules($this->customEntity(), $this, $this->existing());
    }

    /**
     * Business rules from the app's logic class (e.g. one active lease per unit), checked once the fields are valid.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $entity = $this->entity();
            $existing = $this->existing();
            $logic = app(BlueprintRegistry::class)->get($entity->blueprintKey)?->logic();

            foreach ($logic?->validate($entity, $this->payload(), $existing) ?? [] as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $entity = $this->entity();
        $names = ['title' => $entity->titleLabel, 'contact_id' => $entity->contactLabel ?? 'contact', 'amount' => $entity->amountLabel ?? 'amount',
            'occurs_on' => $entity->dateLabel ?? 'date', 'due_on' => $entity->dueLabel ?? 'due date', 'assignee_id' => 'assignee'];
        foreach ($entity->fields as $field) {
            $names['data.'.$field->key] = $field->label;
        }

        return $names + CustomFields::attributes($this->customEntity());
    }

    /** @return array<string, mixed> attributes ready for Record::create / update */
    public function payload(): array
    {
        $entity = $this->entity();
        $validated = $this->validated();

        $data = [];
        foreach ($entity->fields as $field) {
            $data[$field->key] = $field->cast($validated['data'][$field->key] ?? null);
        }

        return [
            'title' => $validated['title'],
            'status' => $validated['status'],
            'branch_id' => $validated['branch_id'] ?? null,
            'contact_id' => $entity->hasContact() ? ($validated['contact_id'] ?? null) : null,
            'assignee_id' => $entity->hasAssignee ? ($validated['assignee_id'] ?? null) : null,
            'amount' => $entity->hasAmount() ? ($validated['amount'] ?? null) : null,
            'currency' => $entity->hasAmount() ? app(WorkspaceContext::class)->get()?->currency_code : null,
            'occurs_on' => $entity->hasDate() ? ($validated['occurs_on'] ?? null) : null,
            'due_on' => $entity->hasDue() ? ($validated['due_on'] ?? null) : null,
            'data' => $data,
            ...CustomFields::payload($this->customEntity(), $this, $this->existing()),
        ];
    }

    protected function prepareForValidation(): void
    {
        // Unchecked checkboxes arrive as "0" from the form component; everything else passes through.
        if (! $this->has('status')) {
            $this->merge(['status' => $this->entity()->defaultStatus()]);
        }
        if ($this->route('record') instanceof Record === false && ! $this->has('data')) {
            $this->merge(['data' => []]);
        }
    }
}
