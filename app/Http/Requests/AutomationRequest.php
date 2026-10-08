<?php

namespace App\Http\Requests;

use App\Support\Automations;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tasks\Models\Task;

/** A "when this happens, do that" rule. Each action type has its own fields. */
class AutomationRequest extends FormRequest
{
    /** Who may manage automations is decided by the settings route group. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'conditions' => array_values(array_filter((array) $this->input('conditions', []), fn ($condition) => is_array($condition) && filled($condition['field'] ?? null))),
            'actions' => array_values(array_filter((array) $this->input('actions', []), 'is_array')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $subject = Automations::subjectKey((string) $this->input('trigger'));
        $fields = Automations::FIELDS[$subject] ?? [];
        $member = Rule::exists('workspace_user', 'user_id')->where('workspace_id', $this->user()->current_workspace_id);

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(array_keys(Automations::TRIGGERS))],
            'is_active' => ['boolean'],
            'conditions' => ['array', 'max:'.Automations::MAX_CONDITIONS],
            'conditions.*.field' => ['required', Rule::in(array_keys($fields))],
            'conditions.*.operator' => ['required', Rule::in(array_keys(Automations::OPERATORS))],
            'actions' => ['required', 'array', 'min:1', 'max:'.Automations::MAX_ACTIONS],
            'actions.*.type' => ['required', Rule::in(array_keys(Automations::ACTIONS))],
        ];

        foreach ((array) $this->input('conditions', []) as $index => $condition) {
            $needsValue = ! in_array($condition['operator'] ?? '', Automations::VALUELESS, true);
            $rules["conditions.$index.value"] = [$needsValue ? 'required' : 'nullable', 'string', 'max:255'];
        }

        foreach ((array) $this->input('actions', []) as $index => $action) {
            $prefix = "actions.$index.";
            $rules += match ($action['type'] ?? null) {
                'notify' => [
                    $prefix.'to' => ['required', Rule::in(array_keys(Automations::RECIPIENTS))],
                    $prefix.'user_id' => ['nullable', 'required_if:'.$prefix.'to,user', 'integer', $member],
                    $prefix.'message' => ['required', 'string', 'max:500'],
                ],
                'create_task' => [
                    $prefix.'title' => ['required', 'string', 'max:200'],
                    $prefix.'description' => ['nullable', 'string', 'max:2000'],
                    $prefix.'assignee' => ['nullable', Rule::in(['', 'assignee', 'user'])],
                    $prefix.'user_id' => ['nullable', 'required_if:'.$prefix.'assignee,user', 'integer', $member],
                    $prefix.'due_in_days' => ['nullable', 'integer', 'min:0', 'max:365'],
                    $prefix.'priority' => ['required', Rule::in(array_keys(Task::PRIORITIES))],
                ],
                'send_email' => [
                    $prefix.'subject' => ['required', 'string', 'max:200'],
                    $prefix.'body' => ['required', 'string', 'max:5000'],
                ],
                'send_sms' => [
                    $prefix.'message' => ['required', 'string', 'max:480'],
                ],
                'update_record' => [
                    $prefix.'field' => ['required', Rule::in(Automations::UPDATABLE[$subject] ?? [])],
                    $prefix.'value' => ['required', Rule::in(array_keys($fields[$action['field'] ?? '']['options'] ?? []))],
                ],
                default => [],
            };
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'actions.*.message' => 'message', 'actions.*.title' => 'task title', 'actions.*.subject' => 'email subject',
            'actions.*.body' => 'email text', 'actions.*.user_id' => 'person', 'actions.*.field' => 'field', 'actions.*.value' => 'new value',
            'conditions.*.field' => 'condition field', 'conditions.*.value' => 'condition value',
        ];
    }

    /**
     * The rule as stored: each action keeps only the fields its type uses.
     *
     * @return array{name: string, trigger: string, is_active: bool, conditions: list<array{field: string, operator: string, value: ?string}>, actions: list<array<string, mixed>>}
     */
    public function automation(): array
    {
        $keep = [
            'notify' => ['to', 'user_id', 'message'],
            'create_task' => ['title', 'description', 'assignee', 'user_id', 'due_in_days', 'priority'],
            'send_email' => ['subject', 'body'],
            'send_sms' => ['message'],
            'update_record' => ['field', 'value'],
        ];
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'conditions' => collect($data['conditions'] ?? [])->map(fn (array $condition) => [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => in_array($condition['operator'], Automations::VALUELESS, true) ? null : ($condition['value'] ?? null),
            ])->values()->all(),
            'actions' => collect($data['actions'])->map(fn (array $action) => ['type' => $action['type']]
                + collect($action)->only($keep[$action['type']])->map(fn ($value, $key) => in_array($key, ['user_id', 'due_in_days'], true) && $value !== null && $value !== '' ? (int) $value : $value)->all()
            )->values()->all(),
        ];
    }
}
