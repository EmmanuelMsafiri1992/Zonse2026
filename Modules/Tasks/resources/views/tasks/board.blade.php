@extends('layouts.app')
@section('title', 'Task board')
@section('content')
    <x-page-header title="Board" sub="Drag cards between columns. Done cards drop off after two weeks." :crumbs="['Tasks' => route('tasks.index'), 'Board']">
        <a href="{{ route('tasks.index') }}" class="btn btn-white"><x-icon name="list-checks" /> List</a>
        @can('create', \Modules\Tasks\Models\Task::class)
            <a href="{{ route('tasks.create') }}" class="btn btn-primary"><x-icon name="plus" /> New task</a>
        @endcan
    </x-page-header>

    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <div class="z-search" style="max-width:280px">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search the board…">
        </div>
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
        @if($filters['q'] || $filters['priority'] || $filters['assignee'])<a href="{{ route('tasks.board') }}" class="btn btn-link btn-sm text-muted">Clear</a>@endif
    </form>

    @php $canMove = auth()->user()->can('create', \Modules\Tasks\Models\Task::class); @endphp
    <div class="z-board" id="taskBoard" data-csrf="{{ csrf_token() }}">
        @foreach($columns as $column)
            <div class="z-board-col" data-status="{{ $column['key'] }}">
                <div class="z-board-head">
                    <span class="fw-600">{{ $column['label'] }}</span>
                    <span class="badge rounded-pill text-bg-light z-board-count">{{ $column['tasks']->count() }}</span>
                    @can('create', \Modules\Tasks\Models\Task::class)
                        <a href="{{ route('tasks.create', ['status' => $column['key']]) }}" class="btn btn-sm btn-icon btn-soft-secondary ms-auto" title="Add here"><x-icon name="plus" class="zi zi-sm" /></a>
                    @endcan
                </div>
                <div class="z-board-cards" data-status="{{ $column['key'] }}">
                    @foreach($column['tasks'] as $task)
                        <a href="{{ route('tasks.show', $task) }}" class="z-board-card {{ $task->isOverdue() ? 'is-overdue' : '' }}" data-task="{{ $task->id }}" data-move="{{ route('tasks.status', $task) }}" draggable="{{ $canMove || $task->assignee_id === auth()->id() ? 'true' : 'false' }}">
                            <div class="d-flex align-items-start gap-2">
                                <span class="z-priority z-priority-{{ $task->priority }}" title="{{ $task->priorityLabel() }} priority"></span>
                                <span class="fw-600 fs-7 flex-grow-1 {{ $task->status === 'done' ? 'text-decoration-line-through text-muted' : '' }}">{{ $task->title }}</span>
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-2 fs-8 text-muted">
                                @if($task->dueLabel())<span class="{{ $task->isOverdue() ? 'text-danger fw-600' : ($task->isDueToday() ? 'text-warning fw-600' : '') }}"><x-icon name="calendar" class="zi zi-sm" /> {{ $task->dueLabel() }}</span>@endif
                                @if($task->contact)<span class="text-truncate"><x-icon name="user-round" class="zi zi-sm" /> {{ $task->contact->displayName() }}</span>@endif
                                @if($task->assignee)<span class="z-avatar z-avatar-sm z-avatar-soft ms-auto" title="{{ $task->assignee->name }}">{{ \Illuminate\Support\Str::of($task->assignee->name)->substr(0, 1) }}</span>@endif
                            </div>
                        </a>
                    @endforeach
                    <div class="z-board-empty fs-8 text-muted">Drop tasks here</div>
                </div>
            </div>
        @endforeach
    </div>

    @push('scripts')
    <script>
    (() => {
        const board = document.getElementById('taskBoard');
        if (!board) return;
        let dragging = null;

        board.querySelectorAll('.z-board-card[draggable="true"]').forEach(card => {
            card.addEventListener('dragstart', e => { dragging = card; card.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; });
            card.addEventListener('dragend', () => { card.classList.remove('is-dragging'); board.querySelectorAll('.z-board-cards').forEach(c => c.classList.remove('is-over')); dragging = null; });
            card.addEventListener('click', e => { if (card.classList.contains('is-dragging')) e.preventDefault(); });
        });

        const refreshCounts = () => board.querySelectorAll('.z-board-col').forEach(col => {
            col.querySelector('.z-board-count').textContent = col.querySelectorAll('.z-board-card').length;
        });

        board.querySelectorAll('.z-board-cards').forEach(list => {
            list.addEventListener('dragover', e => {
                if (!dragging) return;
                e.preventDefault();
                list.classList.add('is-over');
                const after = [...list.querySelectorAll('.z-board-card:not(.is-dragging)')].find(c => e.clientY <= c.getBoundingClientRect().top + c.offsetHeight / 2);
                after ? list.insertBefore(dragging, after) : list.insertBefore(dragging, list.querySelector('.z-board-empty'));
            });
            list.addEventListener('dragleave', () => list.classList.remove('is-over'));
            list.addEventListener('drop', async e => {
                e.preventDefault();
                list.classList.remove('is-over');
                if (!dragging) return;
                const card = dragging;
                const position = [...list.querySelectorAll('.z-board-card')].indexOf(card);
                refreshCounts();
                try {
                    const res = await fetch(card.dataset.move, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': board.dataset.csrf },
                        body: JSON.stringify({ status: list.dataset.status, position }),
                    });
                    if (!res.ok) throw new Error('Could not move the task');
                    const data = await res.json();
                    card.querySelector('.fw-600').classList.toggle('text-decoration-line-through', data.status === 'done');
                    card.querySelector('.fw-600').classList.toggle('text-muted', data.status === 'done');
                    window.zonseo?.toast?.('Moved to ' + data.label, 'success');
                } catch (err) {
                    window.zonseo?.toast?.(err.message, 'danger');
                    window.location.reload();
                }
            });
        });
    })();
    </script>
    @endpush
@endsection
