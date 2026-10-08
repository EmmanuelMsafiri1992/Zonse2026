<?php

namespace Modules\Tasks\Http\Requests;

use App\Support\CustomFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tasks\Models\Task;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;

        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(array_keys(Task::STATUSES))],
            'priority' => ['required', Rule::in(array_keys(Task::PRIORITIES))],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'assignee_id' => ['nullable', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId)],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('workspace_id', $workspaceId)],
        ] + CustomFields::rules('task', $this, $this->route('task'));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['assignee_id' => 'assignee', 'contact_id' => 'contact', 'due_date' => 'due date'] + CustomFields::attributes('task');
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'todo',
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
            'contact_id' => $data['contact_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
        ] + CustomFields::payload('task', $this, $this->route('task'));
    }
}
