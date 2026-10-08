<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Default role rules for module records. Owners and admins are allowed
 * everything by Gate::before; viewers can only read; members and managers may
 * create and edit; managers may delete. Modules extend and override as needed.
 */
abstract class WorkspacePolicy
{
    /** @var list<string> */
    protected array $writeRoles = ['member', 'manager'];

    /** @var list<string> */
    protected array $deleteRoles = ['manager'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $model): bool
    {
        return $this->ownsRecord($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->hasRole($user, $this->writeRoles);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->ownsRecord($user, $model) && $this->hasRole($user, $this->writeRoles);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->ownsRecord($user, $model) && $this->hasRole($user, $this->deleteRoles);
    }

    protected function ownsRecord(User $user, Model $model): bool
    {
        return $model->workspace_id !== null && $user->belongsToWorkspace((int) $model->workspace_id);
    }

    /** @param  list<string>  $roles */
    protected function hasRole(User $user, array $roles): bool
    {
        $workspaceId = $user->current_workspace_id;

        return $workspaceId !== null && in_array($user->roleIn($workspaceId), $roles, true);
    }
}
