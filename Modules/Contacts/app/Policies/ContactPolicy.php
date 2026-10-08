<?php

namespace Modules\Contacts\Policies;

use App\Models\User;
use Modules\Contacts\Models\Contact;

/**
 * Owners and admins are allowed everything by Gate::before. Viewers may only
 * read; members and managers can create and edit; managers may delete.
 */
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->belongsToWorkspace($contact->workspace_id);
    }

    public function create(User $user): bool
    {
        return $this->roleIn($user, ['member', 'manager']);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->belongsToWorkspace($contact->workspace_id) && $this->roleIn($user, ['member', 'manager']);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->belongsToWorkspace($contact->workspace_id) && $this->roleIn($user, ['manager']);
    }

    /** @param  list<string>  $roles */
    protected function roleIn(User $user, array $roles): bool
    {
        $workspaceId = $user->current_workspace_id;

        return $workspaceId !== null && in_array($user->roleIn($workspaceId), $roles, true);
    }
}
