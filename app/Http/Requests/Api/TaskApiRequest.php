<?php

namespace App\Http\Requests\Api;

use App\Support\CustomFields;
use Modules\Tasks\Http\Requests\TaskRequest;
use Modules\Tasks\Models\Task;

class TaskApiRequest extends TaskRequest
{
    /** API updates may send only the fields that change; the rest are filled from the stored item before the module's rules run. */
    protected string $routeKey = 'task';

    /** @return array<string, mixed> */
    protected function currentValues(Task $task): array
    {
        return [
            ...$task->only(['title', 'description', 'status', 'priority', 'assignee_id', 'contact_id', 'branch_id']),
            'due_date' => $task->due_date?->toDateString(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $model = $this->route($this->routeKey);
        $this->replace(CustomFields::fromApi(array_merge($model instanceof Task ? $this->currentValues($model) : ['priority' => 'normal'], $this->all()), $model instanceof Task ? $model : null));
    }
}
