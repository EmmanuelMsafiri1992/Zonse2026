<?php

namespace App\Http\Requests\Api;

use App\Support\CustomFields;
use Illuminate\Validation\Rule;
use Modules\Helpdesk\Http\Requests\TicketRequest;
use Modules\Helpdesk\Models\Ticket;

class TicketApiRequest extends TicketRequest
{
    /** API updates may send only the fields that change; the rest are filled from the stored item before the module's rules run. */
    protected string $routeKey = 'ticket';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['status' => ['nullable', Rule::in(array_keys(Ticket::STATUSES))]];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return parent::payload() + array_filter(['status' => $this->validated('status')]);
    }

    /** @return array<string, mixed> */
    protected function currentValues(Ticket $ticket): array
    {
        return $ticket->only(['subject', 'body', 'contact_id', 'requester_name', 'requester_email', 'channel', 'category', 'priority', 'status', 'assignee_id', 'branch_id']);
    }

    protected function prepareForValidation(): void
    {
        $model = $this->route($this->routeKey);
        $this->replace(CustomFields::fromApi(array_merge($model instanceof Ticket ? $this->currentValues($model) : ['channel' => 'web', 'priority' => 'normal'], $this->all()), $model instanceof Ticket ? $model : null));
    }
}
