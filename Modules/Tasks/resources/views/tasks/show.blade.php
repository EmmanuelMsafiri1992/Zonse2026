@extends('layouts.app')
@section('title', $task->title)
@section('content')
    <x-page-header :title="$task->title" :sub="$task->priorityLabel().' priority'.($task->dueLabel() ? ' · due '.$task->dueLabel() : '').($task->assignee ? ' · '.$task->assignee->name : ' · unassigned')"
                   :crumbs="['Tasks' => route('tasks.index'), \Illuminate\Support\Str::limit($task->title, 40)]">
        @can('changeStatus', $task)
            @foreach($statuses as $key => $label)
                @continue($key === $task->status)
                <form method="POST" action="{{ route('tasks.status', $task) }}">
                    @csrf <input type="hidden" name="status" value="{{ $key }}">
                    <button class="btn {{ $key === 'done' ? 'btn-primary' : 'btn-white' }}">
                        <x-icon :name="['todo' => 'rotate-ccw', 'in_progress' => 'play', 'done' => 'check-check'][$key]" />
                        {{ ['todo' => 'Back to to-do', 'in_progress' => 'Start', 'done' => 'Mark done'][$key] }}
                    </button>
                </form>
            @endforeach
        @endcan
        @can('update', $task)
            <a href="{{ route('tasks.edit', $task) }}" class="btn btn-white"><x-icon name="pencil" /> Edit</a>
        @endcan
        @can('delete', $task)
            <form method="POST" action="{{ route('tasks.destroy', $task) }}" onsubmit="return confirm('Delete this task?')">
                @csrf @method('DELETE')
                <button class="btn btn-soft-danger btn-icon" title="Delete"><x-icon name="trash-2" /></button>
            </form>
        @endcan
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <x-pill :status="$task->status">{{ $task->statusLabel() }}</x-pill>
                        <x-pill :status="$task->priority">{{ $task->priorityLabel() }}</x-pill>
                        @if($task->isOverdue())<span class="fs-8 text-danger fw-600">Overdue</span>@endif
                    </div>
                    <ul class="list-unstyled fs-7 mb-0 d-grid gap-2">
                        <li class="d-flex gap-2"><x-icon name="calendar" class="zi zi-sm text-muted" /> {{ $task->due_date ? $task->due_date->format('l d M Y') : 'No due date' }}</li>
                        <li class="d-flex gap-2"><x-icon name="user-check" class="zi zi-sm text-muted" /> {{ $task->assignee?->name ?? 'Unassigned' }}</li>
                        <li class="d-flex gap-2"><x-icon name="user-round" class="zi zi-sm text-muted" />
                            <span>@if($task->contact)<a href="{{ route('contacts.show', $task->contact) }}">{{ $task->contact->displayName() }}</a>@else Not linked to a contact @endif</span>
                        </li>
                        @if($task->branch)<li class="d-flex gap-2"><x-icon name="map-pin" class="zi zi-sm text-muted" /> {{ $task->branch->name }}</li>@endif
                        @if($task->taskable && method_exists($task->taskable, 'activityLabel'))
                            <li class="d-flex gap-2"><x-icon name="link" class="zi zi-sm text-muted" /><a href="{{ method_exists($task->taskable, 'activityUrl') ? $task->taskable->activityUrl() : '#' }}">{{ $task->taskable->activityLabel() }}</a></li>
                        @endif
                        @if($task->completed_at)<li class="d-flex gap-2 text-success"><x-icon name="check-check" class="zi zi-sm" /> Done {{ $task->completed_at->diffForHumans() }}</li>@endif
                        <li class="d-flex gap-2 text-muted"><x-icon name="info" class="zi zi-sm" /> Added {{ $task->created_at?->diffForHumans() }}{{ $task->creator ? ' by '.$task->creator->name : '' }}</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            @if($task->description)
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Details</h5></div>
                    <div class="card-body fs-7" style="white-space:pre-line">{{ $task->description }}</div>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><h5 class="card-title">Comments</h5></div>
                @can('changeStatus', $task)
                    <form method="POST" action="{{ route('tasks.comments.store', $task) }}" class="card-body border-bottom">
                        @csrf
                        <x-form.textarea name="body" placeholder="Progress, blockers, questions…" rows="2" required />
                        <button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> Comment</button>
                    </form>
                @endcan
                <div class="card-body">
                    @if($task->comments->isEmpty())
                        <x-empty icon="message-square" title="No comments yet" class="py-3" />
                    @else
                        <div class="z-timeline">
                            @foreach($task->comments as $note)
                                <div class="z-timeline-item">
                                    <span class="z-avatar z-avatar-sm z-avatar-soft">{{ \Illuminate\Support\Str::of($note->authorName())->substr(0, 1) }}</span>
                                    <div>
                                        <div class="fs-8 text-muted"><strong class="text-body">{{ $note->authorName() }}</strong> · {{ $note->created_at->diffForHumans() }}</div>
                                        <div class="fs-7" style="white-space:pre-line">{{ $note->body }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
