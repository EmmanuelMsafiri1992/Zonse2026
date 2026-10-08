<?php

namespace App\Http\Requests;

use App\Models\CustomField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An extra field on one kind of record. The kind is chosen once, when the field is made. */
class CustomFieldRequest extends FormRequest
{
    /** Who may manage fields is decided by the settings route group. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $options = $this->input('options');
        if (is_string($options)) {
            $options = preg_split('/\R/', $options) ?: [];
        }

        $this->merge([
            'options' => collect((array) $options)->map(fn ($option) => is_string($option) ? trim($option) : $option)->filter(fn ($option) => filled($option))->unique()->values()->all(),
            'is_required' => $this->boolean('is_required'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $field = $this->route('custom_field');
        $entity = $field instanceof CustomField ? $field->entity : $this->input('entity');

        return [
            'entity' => $field instanceof CustomField ? ['prohibited'] : ['required', Rule::in(array_keys(CustomField::ENTITIES))],
            'label' => ['required', 'string', 'max:120', Rule::unique('custom_fields', 'label')
                ->where('workspace_id', $this->user()->current_workspace_id)->where('entity', $entity)
                ->ignore($field instanceof CustomField ? $field->id : null)],
            'type' => ['required', Rule::in(array_keys(CustomField::TYPES))],
            'options' => ['array', 'max:50', Rule::requiredIf(fn () => $this->input('type') === 'select')],
            'options.*' => ['string', 'max:100'],
            'is_required' => ['boolean'],
            'help' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.unique' => 'There is already a field with this name.',
            'options.required' => 'Add at least one choice, one per line.',
            'entity.prohibited' => 'A field cannot be moved to another kind of record.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['label' => 'name', 'options.*' => 'choice', 'entity' => 'record type'];
    }

    /**
     * @return array{label: string, type: string, options: ?list<string>, is_required: bool, help: ?string, entity?: string}
     */
    public function field(): array
    {
        $data = $this->validated();

        return [
            'label' => $data['label'],
            'type' => $data['type'],
            'options' => $data['type'] === 'select' ? array_values($data['options']) : null,
            'is_required' => (bool) $data['is_required'],
            'help' => $data['help'] ?? null,
        ] + (isset($data['entity']) ? ['entity' => $data['entity']] : []);
    }
}
