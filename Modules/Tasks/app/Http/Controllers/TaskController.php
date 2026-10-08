<?php

namespace Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Contacts\Models\Contact;
use Modules\Tasks\Http\Requests\TaskRequest;
use Modules\Tasks\Models\Task;

class TaskController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        $filters = $this->filters($request);
        $today = Task::localToday();

        $query = Task::query()->with(['contact', 'assignee'])
            ->search($filters['q'])->priority($filters['priority'])->assignedTo($filters['assignee'])->forContact($filters['contact']);

        $query = match ($filters['status']) {
            'open' => $query->open(),
            'all' => $query,
            default => $query->status($filters['status']),
        };

        $query = match ($filters['due']) {
            'overdue' => $query->overdue(),
            'today' => $query->dueOn($today),
            'week' => $query->dueBetween($today, $today->addDays(7)),
            'none' => $query->whereNull('due_date'),
            default => $query,
        };

        $tasks = $filters['status'] === 'done'
            ? $query->orderByDesc('completed_at')->paginate(25)->withQueryString()
            : $query->orderByUrgency()->paginate(25)->withQueryString();

        return view('tasks::tasks.index', [
            'tasks' => $tasks,
            'filters' => $filters,
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'members' => $this->memberOptions(),
            'stats' => $this->stats($request->user()->id),
        ]);
    }

    public function board(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        $filters = $this->filters($request);
        $tasks = Task::query()->with(['contact', 'assignee'])
            ->search($filters['q'])->priority($filters['priority'])->assignedTo($filters['assignee'])->forContact($filters['contact'])
            ->where(fn ($q) => $q->open()->orWhere('completed_at', '>=', now()->subDays(14)))
            ->orderBy('position')->orderBy('id')->get();

        $columns = collect(Task::STATUSES)->map(fn (string $label, string $status) => [
            'key' => $status,
            'label' => $label,
            'tasks' => $tasks->where('status', $status)->values(),
        ]);

        return view('tasks::tasks.board', [
            'columns' => $columns,
            'filters' => $filters,
            'priorities' => Task::PRIORITIES,
            'members' => $this->memberOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Task::class);

        $task = new Task([
            'contact_id' => $request->query('contact'),
            'assignee_id' => $request->query('assignee', $request->user()->id),
            'status' => $request->query('status', 'todo'),
            'due_date' => $request->query('due'),
        ]);

        return view('tasks::tasks.form', $this->formData($task));
    }

    public function store(TaskRequest $request): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $payload = $request->payload();
        $payload['position'] = (int) Task::query()->where('status', $payload['status'])->max('position') + 1;
        $task = Task::create($payload);

        $target = $request->input('_return') === 'board' ? route('tasks.board') : route('tasks.show', $task);

        return redirect($target)->with('flash', ['type' => 'success', 'message' => 'Task added.']);
    }

    public function show(Task $task): View
    {
        $this->authorize('view', $task);

        $task->load(['contact', 'assignee', 'branch', 'creator', 'comments.user', 'taskable']);

        return view('tasks::tasks.show', ['task' => $task, 'statuses' => Task::STATUSES]);
    }

    public function edit(Task $task): View
    {
        $this->authorize('update', $task);

        return view('tasks::tasks.form', $this->formData($task));
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $task->update($request->payload());

        return redirect()->route('tasks.show', $task)->with('flash', ['type' => 'success', 'message' => 'Task updated.']);
    }

    /** Change status from a button, a checkbox or a board drag; position only matters on the board. */
    public function status(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        $this->authorize('changeStatus', $task);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Task::STATUSES))],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('position', $data) && $data['position'] !== null) {
            $task->moveTo($data['status'], (int) $data['position']);
        } else {
            $task->update(['status' => $data['status']]);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $task->status, 'label' => $task->statusLabel()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => $task->status === 'done' ? 'Task done. Nice.' : 'Task marked '.strtolower($task->statusLabel()).'.']);
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

        return redirect()->route('tasks.index')->with('flash', ['type' => 'success', 'message' => 'Task deleted.']);
    }

    public function comment(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('changeStatus', $task);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $task->addComment($data['body'], $request->user(), true);

        return back()->with('flash', ['type' => 'success', 'message' => 'Comment added.']);
    }

    /** @return array{q: string, status: string, priority: ?string, assignee: ?string, contact: ?string, due: string} */
    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'open');

        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($status, array_merge(['open', 'all'], array_keys(Task::STATUSES)), true) ? $status : 'open',
            'priority' => $request->query('priority') ?: null,
            'assignee' => $request->query('assignee') ?: null,
            'contact' => $request->query('contact') ?: null,
            'due' => in_array($request->query('due'), ['overdue', 'today', 'week', 'none'], true) ? $request->query('due') : 'any',
        ];
    }

    /** @return array<string, int> */
    protected function stats(int $userId): array
    {
        $today = Task::localToday();

        return [
            'mine' => Task::query()->open()->where('assignee_id', $userId)->count(),
            'overdue' => Task::query()->overdue()->count(),
            'today' => Task::query()->open()->dueOn($today)->count(),
            'done_week' => Task::query()->where('status', 'done')->where('completed_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /** @return Collection<int, string> */
    protected function memberOptions(): Collection
    {
        return app(WorkspaceContext::class)->getOrFail()->members()->orderBy('name')->get()->pluck('name', 'id');
    }

    /** @return array<string, mixed> */
    protected function formData(Task $task): array
    {
        return [
            'task' => $task,
            'contacts' => Contact::query()->active()->orderBy('name')->get()->mapWithKeys(fn (Contact $c) => [$c->id => $c->displayName()]),
            'members' => $this->memberOptions(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
        ];
    }
}
