<?php

namespace Modules\Tasks\Policies;

use App\Models\User;
use App\Policies\WorkspacePolicy;
use Modules\Tasks\Models\Task;

class TaskPolicy extends WorkspacePolicy
{
    /** Anyone a task is assigned to may tick it off or move it, even a viewer. */
    public function changeStatus(User $user, Task $task): bool
    {
        return $this->update($user, $task) || ($this->ownsRecord($user, $task) && $task->assignee_id === $user->id);
    }
}
