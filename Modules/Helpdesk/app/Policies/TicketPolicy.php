<?php

namespace Modules\Helpdesk\Policies;

use App\Models\User;
use App\Policies\WorkspacePolicy;
use Modules\Helpdesk\Models\Ticket;

class TicketPolicy extends WorkspacePolicy
{
    /** Whoever a ticket is assigned to can reply and change its status, even a viewer. */
    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket) || ($this->ownsRecord($user, $ticket) && $ticket->assignee_id === $user->id);
    }
}
