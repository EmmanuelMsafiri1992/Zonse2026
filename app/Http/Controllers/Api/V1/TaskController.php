<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\TaskApiRequest;
use App\Http\Resources\V1\TaskResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Tasks\Models\Task;

class TaskController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Task::class);

        $tasks = Task::query()->search($request->string('q')->toString())
            ->status($request->string('status')->toString() ?: null)
            ->assignedTo($request->input('assignee_id'))
            ->forContact($request->input('contact_id'))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return TaskResource::collection($tasks);
    }

    public function show(Task $task): TaskResource
    {
        $this->authorize('view', $task);

        return new TaskResource($task);
    }

    public function store(TaskApiRequest $request): JsonResponse
    {
        $this->authorize('create', Task::class);

        $payload = $request->payload();
        $payload['position'] = (int) Task::query()->where('status', $payload['status'])->max('position') + 1;

        return (new TaskResource(Task::create($payload)))->response()->setStatusCode(201);
    }

    public function update(TaskApiRequest $request, Task $task): TaskResource
    {
        $this->authorize('update', $task);

        $task->update($request->payload());

        return new TaskResource($task);
    }

    public function destroy(Task $task): Response
    {
        $this->authorize('delete', $task);

        $task->delete();

        return response()->noContent();
    }
}
