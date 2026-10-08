@extends('layouts.app')
@section('title', 'Tasks')
@section('content')
    <x-page-header title="Tasks" sub="Everything the team needs to get done, sorted by urgency." :crumbs="['Tasks']">
        <a href="{{ route('tasks.board') }}" class="btn btn-white"><x-icon name="kanban-square" /> Board</a>
        @can('create', \Modules\Tasks\Models\Task::class)
            <a href="{{ route('tasks.create') }}" class="btn btn-primary"><x-icon name="plus" /> New task</a>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-stat label="Assigned to me" :value="$stats['mine']" icon="user-check" color="primary" :href="route('tasks.index', ['assignee' => auth()->id()])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Overdue" :value="$stats['overdue']" icon="alarm-clock" color="danger" :href="route('tasks.index', ['due' => 'overdue'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Due today" :value="$stats['today']" icon="sun" color="warning" :href="route('tasks.index', ['due' => 'today'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Done this week" :value="$stats['done_week']" icon="check-check" color="success" :href="route('tasks.index', ['status' => 'done'])" /></div>
    </div>

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                @if($filters['contact'])<input type="hidden" name="contact" value="{{ $filters['contact'] }}">@endif
                <div class="z-search flex-grow-1" style="max-width:300px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search tasks…">
                </div>
                <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="open" @selected($filters['status'] === 'open')>Open</option>
                    @foreach($statuses as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach
                    <option value="all" @selected($filters['status'] === 'all')>Everything</option>
                </select>
                <select name="due" class="form-select w-auto" onchange="this.form.submit()">
                    @foreach(['any' => 'Any due date', 'overdue' => 'Overdue', 'today' => 'Due today', 'week' => 'Due in 7 days', 'none' => 'No due date'] as $key => $label)
                        <option value="{{ $key }}" @selected($filters['due'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="priority" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">Any priority</option>
                    @foreach($priorities as $key => $label)<option value="{{ $key }}" @selected($filters['priority'] === $key)>{{ $label }}</option>@endforeach
                </select>
                <select name="assignee" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">Anyone</option>
                    <option value="unassigned" @selected($filters['assignee'] === 'unassigned')>Unassigned</option>
                    @foreach($members as $id => $name)<option value="{{ $id }}" @selected((string) $filters['assignee'] === (string) $id)>{{ $name }}</option>@endforeach
                </select>
                <button class="btn btn-soft-primary">Filter</button>
                @if($filters['q'] || $filters['priority'] || $filters['assignee'] || $filters['contact'] || $filters['status'] !== 'open' || $filters['due'] !== 'any')
                    <a href="{{ route('tasks.index') }}" class="btn btn-link btn-sm text-muted">Clear</a>
                @endif
            </form>
            <span class="fs-8 text-muted">{{ $tasks->total() }} {{ \Illuminate\Support\Str::plural('task', $tasks->total()) }}</span>
        </div>

        @if($tasks->isEmpty())
            <div class="card-body">
                <x-empty icon="check-square" title="Nothing here" text="{{ $filters['q'] || $filters['priority'] || $filters['due'] !== 'any' ? 'Nothing matches your filters.' : 'Add a task for yourself or a teammate and it shows up here and on the board.' }}">
                    @can('create', \Modules\Tasks\Models\Task::class)
                        <a href="{{ route('tasks.create') }}" class="btn btn-primary"><x-icon name="plus" /> New task</a>
                    @endcan
                </x-empty>
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th style="width:40px"></th><th>Task</th><th>Priority</th><th>Due</th><th>Assignee</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach($tasks as $task)
                        <tr class="{{ $task->status === 'done' ? 'opacity-75' : '' }}">
                            <td>
                                @can('changeStatus', $task)
                                    <form method="POST" action="{{ route('tasks.status', $task) }}" class="m-0">
                                        @csrf <input type="hidden" name="status" value="{{ $task->status === 'done' ? 'todo' : 'done' }}">
                                        <button class="btn btn-sm btn-icon {{ $task->status === 'done' ? 'btn-success' : 'btn-white' }}" title="{{ $task->status === 'done' ? 'Reopen' : 'Mark done' }}"><x-icon name="check" class="zi zi-sm" /></button>
                                    </form>
                                @endcan
                            </td>
                            <td>
                                <a href="{{ route('tasks.show', $task) }}" class="text-reset text-decoration-none">
                                    <span class="z-row-title d-block {{ $task->status === 'done' ? 'text-decoration-line-through' : '' }}">{{ $task->title }}</span>
                                    @if($task->contact)<span class="z-row-sub"><x-icon name="user-round" class="zi zi-sm" /> {{ $task->contact->displayName() }}</span>@endif
                                </a>
                            </td>
                            <td><x-pill :status="$task->priority">{{ $task->priorityLabel() }}</x-pill></td>
                            <td class="fs-7 {{ $task->isOverdue() ? 'text-danger fw-600' : ($task->isDueToday() ? 'text-warning fw-600' : '') }}">{{ $task->dueLabel() ?? '—' }}</td>
                            <td class="fs-7">{{ $task->assignee?->name ?? 'Unassigned' }}</td>
                            <td><x-pill :status="$task->status">{{ $task->statusLabel() }}</x-pill></td>
                            <td class="text-end">
                                <div class="z-row-actions d-inline-flex gap-1">
                                    <a href="{{ route('tasks.show', $task) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="View"><x-icon name="eye" class="zi zi-sm" /></a>
                                    @can('update', $task)
                                        <a href="{{ route('tasks.edit', $task) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="Edit"><x-icon name="pencil" class="zi zi-sm" /></a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($tasks->hasPages())
                <div class="card-footer">{{ $tasks->links() }}</div>
            @endif
        @endif
    </div>
@endsection
