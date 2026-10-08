<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Helpdesk\Models\Ticket;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status,
            'priority' => $this->priority,
            'channel' => $this->channel,
            'category' => $this->category,
            'contact_id' => $this->contact_id,
            'requester_name' => $this->requester_name,
            'requester_email' => $this->requester_email,
            'assignee_id' => $this->assignee_id,
            'branch_id' => $this->branch_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
