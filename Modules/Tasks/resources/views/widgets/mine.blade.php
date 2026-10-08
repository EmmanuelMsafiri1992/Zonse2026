<div class="card h-100">
    <div class="card-header">
        <h5 class="card-title"><x-icon name="check-square" class="zi me-1" /> My tasks</h5>
        <div class="d-flex gap-1">
            @can('create', \Modules\Tasks\Models\Task::class)<a href="{{ route('tasks.create') }}" class="btn btn-sm btn-primary"><x-icon name="plus" class="zi zi-sm" /> Add</a>@endcan
            <a href="{{ route('tasks.board') }}" class="btn btn-sm btn-soft-primary">Board</a>
        </div>
    </div>
    <div class="card-body pb-0">
        <div class="row g-2">
            <div class="col-6"><div class="fs-8 text-muted text-uppercase">Open</div><div class="fs-5 fw-600">{{ $openCount }}</div></div>
            <div class="col-6"><div class="fs-8 text-muted text-uppercase">Overdue</div><div class="fs-5 fw-600 {{ $overdueCount ? 'text-danger' : '' }}">{{ $overdueCount }}</div></div>
        </div>
    </div>
    @if($tasks->isEmpty())
        <div class="card-body"><x-empty icon="party-popper" title="All clear" text="Nothing assigned to you right now." class="py-2" /></div>
    @else
        <div class="list-group list-group-flush mt-3">
            @foreach($tasks as $task)
                <div class="list-group-item d-flex align-items-center gap-2">
                    <form method="POST" action="{{ route('tasks.status', $task) }}" class="m-0">
                        @csrf <input type="hidden" name="status" value="done">
                        <button class="btn btn-sm btn-icon btn-white" title="Mark done"><x-icon name="check" class="zi zi-sm" /></button>
                    </form>
                    <a href="{{ route('tasks.show', $task) }}" class="mw-0 flex-grow-1 text-reset text-decoration-none">
                        <span class="d-block fw-600 fs-7 text-truncate">{{ $task->title }}</span>
                        <span class="d-block fs-8 text-truncate {{ $task->isOverdue() ? 'text-danger' : 'text-muted' }}">{{ $task->dueLabel() ? 'Due '.$task->dueLabel() : 'No due date' }}{{ $task->contact ? ' · '.$task->contact->displayName() : '' }}</span>
                    </a>
                    <x-pill :status="$task->priority">{{ $task->priorityLabel() }}</x-pill>
                </div>
            @endforeach
        </div>
    @endif
</div>
