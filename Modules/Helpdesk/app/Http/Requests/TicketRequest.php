<?php

namespace Modules\Helpdesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Helpdesk\Models\Ticket;

class TicketRequest extends FormRequest
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
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'requester_name' => ['nullable', 'string', 'max:160', 'required_without:contact_id'],
            'requester_email' => ['nullable', 'email', 'max:190'],
            'channel' => ['required', Rule::in(array_keys(Ticket::CHANNELS))],
            'category' => ['nullable', 'string', 'max:60'],
            'priority' => ['required', Rule::in(array_keys(Ticket::PRIORITIES))],
            'assignee_id' => ['nullable', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('workspace_id', $workspaceId)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['requester_name.required_without' => 'Pick a contact or type the requester\'s name.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['contact_id' => 'contact', 'assignee_id' => 'assignee', 'requester_name' => 'requester name', 'requester_email' => 'requester email'];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'subject' => $data['subject'],
            'body' => $data['body'] ?? null,
            'contact_id' => $data['contact_id'] ?? null,
            'requester_name' => ($data['contact_id'] ?? null) ? null : ($data['requester_name'] ?? null),
            'requester_email' => ($data['contact_id'] ?? null) ? null : ($data['requester_email'] ?? null),
            'channel' => $data['channel'],
            'category' => $data['category'] ?? null,
            'priority' => $data['priority'],
            'assignee_id' => $data['assignee_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
        ];
    }
}
