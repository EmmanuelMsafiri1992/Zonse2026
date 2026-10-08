@extends('layouts.app')
@section('title', $record->number.' · '.$record->title)
@section('content')
    <x-page-header :title="$record->title" :sub="$record->number.' · '.$def->label.' · '.$app->name"
                   :crumbs="['Apps' => route('apps.index'), $app->name => route('apps.show', $app->key), $def->plural => route('apps.records.index', [$app->key, $def->key]), $record->number]">
        @foreach($documents as $documentKey => $documentLabel)
            <a href="{{ route('apps.records.document', [$app->key, $def->key, $record->id, $documentKey]) }}" target="_blank" class="btn btn-white"><x-icon name="printer" /> {{ $documentLabel }}</a>
        @endforeach
        @can('use-assistant')
            <form method="POST" action="{{ route('assistant.store') }}">
                @csrf
                <input type="hidden" name="record_id" value="{{ $record->id }}">
                <input type="hidden" name="question" value="Summarise {{ $record->number }}: what it is, where it stands and anything that needs attention.">
                <button class="btn btn-white"><x-icon name="sparkles" /> Summarise with AI</button>
            </form>
        @endcan
        @can('update', $record)
            <a href="{{ route('apps.records.edit', [$app->key, $def->key, $record->id]) }}" class="btn btn-white"><x-icon name="pencil" /> Edit</a>
        @endcan
        @can('delete', $record)
            <form method="POST" action="{{ route('apps.records.destroy', [$app->key, $def->key, $record->id]) }}" onsubmit="return confirm('Delete this {{ strtolower($def->label) }}?')">
                @csrf @method('DELETE')
                <button class="btn btn-soft-danger btn-icon" title="Delete"><x-icon name="trash-2" /></button>
            </form>
        @endcan
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3"><x-pill :status="$record->statusTone()">{{ $record->statusLabel() }}</x-pill></div>
                    <ul class="list-unstyled fs-7 mb-0 d-grid gap-2">
                        @if($def->hasContact())
                            <li class="d-flex gap-2"><x-icon name="user-round" class="zi zi-sm text-muted" />
                                <span>{{ $def->contactLabel }}:
                                    @if($record->contact)<a href="{{ route('contacts.show', $record->contact) }}">{{ $record->contact->displayName() }}</a>@else<span class="text-muted">—</span>@endif
                                </span>
                            </li>
                        @endif
                        @if($def->hasAssignee)<li class="d-flex gap-2"><x-icon name="user-check" class="zi zi-sm text-muted" /> {{ $record->assignee?->name ?? 'Unassigned' }}</li>@endif
                        @if($def->hasAmount())<li class="d-flex gap-2"><x-icon name="banknote" class="zi zi-sm text-muted" /> {{ $def->amountLabel }}: {{ $record->amount !== null ? \App\Support\Money::format($record->amount, $record->currency) : '—' }}</li>@endif
                        @if($def->hasDate())<li class="d-flex gap-2"><x-icon name="calendar" class="zi zi-sm text-muted" /> {{ $def->dateLabel }}: {{ $record->occurs_on?->format('d M Y') ?? '—' }}</li>@endif
                        @if($def->hasDue())
                            <li class="d-flex gap-2 {{ $record->due_on && $record->due_on->isPast() && ! $record->isDone() ? 'text-danger' : '' }}"><x-icon name="calendar-clock" class="zi zi-sm text-muted" /> {{ $def->dueLabel }}: {{ $record->due_on?->format('d M Y') ?? '—' }}</li>
                        @endif
                        @if($record->branch)<li class="d-flex gap-2"><x-icon name="map-pin" class="zi zi-sm text-muted" /> {{ $record->branch->name }}</li>@endif
                        <li class="d-flex gap-2 text-muted"><x-icon name="info" class="zi zi-sm" /> Added {{ $record->created_at?->diffForHumans() }}{{ $record->creator ? ' by '.$record->creator->name : '' }}</li>
                    </ul>
                </div>
            </div>

            @can('update', $record)
                @if($actions)
                    <div class="card mb-3">
                        <div class="card-header"><h5 class="card-title">Actions</h5></div>
                        <div class="card-body d-grid gap-2">
                            @foreach($actions as $actionKey => $action)
                                <form method="POST" action="{{ route('apps.records.action', [$app->key, $def->key, $record->id, $actionKey]) }}"
                                      @isset($action['confirm']) onsubmit="return confirm(@js($action['confirm']))" @endisset>
                                    @csrf
                                    @foreach($action['fields'] ?? [] as $field)
                                        @if($field['type'] === 'select')
                                            <x-form.select :name="$field['name']" :label="$field['label']" :options="$field['options'] ?? []" :value="$field['value'] ?? null" class="form-select-sm" />
                                        @else
                                            <x-form.input :name="$field['name']" :label="$field['label']" :type="$field['type']" :value="$field['value'] ?? null" class="form-control-sm" />
                                        @endif
                                    @endforeach
                                    <button class="btn btn-sm btn-soft-primary w-100"><x-icon :name="$action['icon']" class="zi zi-sm" /> {{ $action['label'] }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endcan

            @if($billable)
                @include('apps.records.partials.billing')
            @endif

            @can('update', $record)
                @if(count($def->statuses) > 1)
                    <div class="card mb-3">
                        <div class="card-header"><h5 class="card-title">Move to</h5></div>
                        <div class="card-body d-flex flex-wrap gap-2">
                            @foreach($def->statuses as $status => $label)
                                @continue($status === $record->status)
                                <form method="POST" action="{{ route('apps.records.status', [$app->key, $def->key, $record->id]) }}">
                                    @csrf <input type="hidden" name="status" value="{{ $status }}">
                                    <button class="btn btn-sm btn-white">{{ $label }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endcan
        </div>

        <div class="col-lg-8">
            @foreach($cards as $card)
                @include($card['view'], $card['data'])
            @endforeach

            @if($def->fields)
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Details</h5></div>
                    <div class="card-body">
                        <dl class="row mb-0 fs-7">
                            @foreach($def->fields as $field)
                                <dt class="col-sm-4 text-muted fw-normal">{{ $field->label }}</dt>
                                <dd class="col-sm-8" @if($field->type === 'textarea') style="white-space:pre-line" @endif>
                                    @if($field->type === 'record' && $related[$field->key])
                                        <a href="{{ $related[$field->key]->url() }}">{{ $related[$field->key]->title }}</a> <span class="text-muted">{{ $related[$field->key]->number }}</span>
                                    @elseif($field->type === 'url' && $record->value($field->key))
                                        <a href="{{ $record->value($field->key) }}" target="_blank" rel="noopener">{{ $record->value($field->key) }}</a>
                                    @else
                                        {{ $record->displayValue($field) ?: '—' }}
                                    @endif
                                </dd>
                            @endforeach
                        </dl>
                    </div>
                </div>
            @endif

            @foreach($linked as $link)
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="card-title"><x-icon :name="$link['entity']->icon" class="zi me-1" /> {{ $link['entity']->plural }} <span class="text-muted fs-7">({{ $link['count'] }})</span></h5>
                        <div class="d-flex gap-1">
                            @can('create', \App\Models\Record::class)
                                <a href="{{ route('apps.records.create', [$app->key, $link['entity']->key, 'data' => [$link['field']->key => $record->id]]) }}" class="btn btn-sm btn-soft-primary"><x-icon name="plus" class="zi zi-sm" /> {{ $link['entity']->label }}</a>
                            @endcan
                        </div>
                    </div>
                    @if($link['records']->isEmpty())
                        <div class="card-body fs-7 text-muted">None yet.</div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($link['records'] as $child)
                                <a href="{{ $child->url() }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                                    <span class="mw-0 flex-grow-1">
                                        <span class="d-block fw-600 fs-7 text-truncate">{{ $child->title }}</span>
                                        <span class="d-block fs-8 text-muted">{{ $child->number }} · {{ ($child->occurs_on ?? $child->created_at)?->format('d M Y') }}</span>
                                    </span>
                                    <x-pill :status="$child->statusTone()">{{ $child->statusLabel() }}</x-pill>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            <div class="card">
                <div class="card-header"><h5 class="card-title">Notes</h5></div>
                <div class="card-body">
                    @if($record->comments->isNotEmpty())
                        <div class="z-timeline mb-3">
                            @foreach($record->comments->sortBy('created_at') as $comment)
                                <div class="z-timeline-item">
                                    <span class="z-avatar z-avatar-sm z-avatar-soft">{{ \Illuminate\Support\Str::of($comment->authorName())->substr(0, 1) }}</span>
                                    <div class="flex-grow-1">
                                        <div class="fs-8 text-muted"><strong class="text-body">{{ $comment->authorName() }}</strong> · {{ $comment->created_at->diffForHumans() }}</div>
                                        <div class="fs-7" style="white-space:pre-line">{{ $comment->body }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <form method="POST" action="{{ route('apps.records.comments.store', [$app->key, $def->key, $record->id]) }}">
                        @csrf
                        <x-form.textarea name="body" rows="2" placeholder="Add a note for your team…" required />
                        <div class="text-end"><button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> Add note</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
