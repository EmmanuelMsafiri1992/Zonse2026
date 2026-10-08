@extends('layouts.app')
@section('title', $def->plural.' · '.$app->name)
@section('content')
    @php $filtered = $filters['q'] || $filters['status'] || $filters['contact'] || $filters['assignee']; @endphp
    <x-page-header :title="$def->plural" :sub="$def->description ?? $app->name"
                   :crumbs="['Apps' => route('apps.index'), $app->name => route('apps.show', $app->key), $def->plural]">
        <a href="{{ route('apps.records.export', [$app->key, $def->key, 'q' => $filters['q'], 'status' => $filters['status']]) }}" class="btn btn-white"><x-icon name="download" /> Export CSV</a>
        @can('manage-workspace')
            <a href="{{ route('settings.imports.index', ['target' => 'records.'.$app->key.'.'.$def->key]) }}" class="btn btn-white"><x-icon name="file-up" /> Import</a>
        @endcan
        @can('create', \App\Models\Record::class)
            <a href="{{ route('apps.records.create', [$app->key, $def->key, 'contact' => $filters['contact']]) }}" class="btn btn-primary"><x-icon name="plus" /> New {{ strtolower($def->label) }}</a>
        @endcan
    </x-page-header>

    @if(count($def->statuses) > 1)
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('apps.records.index', [$app->key, $def->key, 'contact' => $filters['contact']]) }}" class="btn btn-sm {{ $filters['status'] === '' ? 'btn-primary' : 'btn-white' }}">All · {{ $counts->sum() }}</a>
            @foreach($def->statuses as $status => $label)
                <a href="{{ route('apps.records.index', [$app->key, $def->key, 'status' => $status, 'contact' => $filters['contact']]) }}"
                   class="btn btn-sm {{ $filters['status'] === $status ? 'btn-primary' : 'btn-white' }}">{{ $label }} · {{ $counts[$status] ?? 0 }}</a>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                @if($filters['status'])<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
                @if($filters['contact'])<input type="hidden" name="contact" value="{{ $filters['contact'] }}">@endif
                <div class="z-search flex-grow-1" style="max-width:300px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search {{ strtolower($def->plural) }}…">
                </div>
                @if($def->hasAssignee)
                    <select name="assignee" class="form-select w-auto" onchange="this.form.submit()">
                        <option value="">Anyone</option>
                        <option value="me" @selected($filters['assignee'] === 'me')>Me</option>
                        <option value="unassigned" @selected($filters['assignee'] === 'unassigned')>Unassigned</option>
                        @foreach($members as $id => $name)<option value="{{ $id }}" @selected((string) $filters['assignee'] === (string) $id)>{{ $name }}</option>@endforeach
                    </select>
                @endif
                <button class="btn btn-soft-primary">Filter</button>
                @if($filtered)
                    <a href="{{ route('apps.records.index', [$app->key, $def->key]) }}" class="btn btn-link btn-sm text-muted">Clear</a>
                @endif
            </form>
            @if($contactName)<span class="z-pill z-pill-primary">{{ $def->contactLabel }}: {{ $contactName }}</span>@endif
            <span class="fs-8 text-muted">{{ $records->total() }} {{ \Illuminate\Support\Str::plural(strtolower($def->label), $records->total()) }}</span>
        </div>

        @if($records->isEmpty())
            <div class="card-body">
                <x-empty :icon="$def->icon" :title="$filtered ? 'Nothing matches' : 'No '.strtolower($def->plural).' yet'"
                         :text="$filtered ? 'Try a different search or status.' : 'Add your first '.strtolower($def->label).' to get started.'">
                    @can('create', \App\Models\Record::class)
                        <a href="{{ route('apps.records.create', [$app->key, $def->key]) }}" class="btn btn-primary"><x-icon name="plus" /> New {{ strtolower($def->label) }}</a>
                    @endcan
                </x-empty>
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead>
                    <tr>
                        <th>{{ $def->titleLabel }}</th>
                        @if($def->hasContact())<th>{{ $def->contactLabel }}</th>@endif
                        @foreach($def->listFields() as $field)<th>{{ $field->label }}</th>@endforeach
                        @if($def->hasDate())<th>{{ $def->dateLabel }}</th>@endif
                        @if($def->hasDue())<th>{{ $def->dueLabel }}</th>@endif
                        @if($def->hasAmount())<th class="text-end">{{ $def->amountLabel }}</th>@endif
                        @if($def->hasAssignee)<th>Assignee</th>@endif
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($records as $record)
                        <tr class="{{ $record->isDone() ? 'opacity-75' : '' }}">
                            <td>
                                <a href="{{ $record->url() }}" class="text-reset text-decoration-none">
                                    <span class="z-row-title d-block">{{ $record->title }}</span>
                                    <span class="z-row-sub">{{ $record->number }}</span>
                                </a>
                            </td>
                            @if($def->hasContact())
                                <td class="fs-7">
                                    @if($record->contact)<a href="{{ route('contacts.show', $record->contact) }}">{{ $record->contact->displayName() }}</a>@else<span class="text-muted">—</span>@endif
                                </td>
                            @endif
                            @foreach($def->listFields() as $field)
                                <td class="fs-7">{{ $record->displayValue($field) ?: '—' }}</td>
                            @endforeach
                            @if($def->hasDate())<td class="fs-7">{{ $record->occurs_on?->format('d M Y') ?? '—' }}</td>@endif
                            @if($def->hasDue())
                                <td class="fs-7 {{ $record->due_on && $record->due_on->isPast() && ! $record->isDone() ? 'text-danger fw-600' : '' }}">{{ $record->due_on?->format('d M Y') ?? '—' }}</td>
                            @endif
                            @if($def->hasAmount())<td class="fs-7 text-end">{{ $record->amount !== null ? \App\Support\Money::format($record->amount, $record->currency) : '—' }}</td>@endif
                            @if($def->hasAssignee)<td class="fs-7">{{ $record->assignee?->name ?? 'Unassigned' }}</td>@endif
                            <td><x-pill :status="$record->statusTone()">{{ $record->statusLabel() }}</x-pill></td>
                            <td class="text-end">
                                <div class="z-row-actions d-inline-flex gap-1">
                                    <a href="{{ $record->url() }}" class="btn btn-sm btn-icon btn-soft-secondary" title="View"><x-icon name="eye" class="zi zi-sm" /></a>
                                    @can('update', $record)
                                        <a href="{{ route('apps.records.edit', [$app->key, $def->key, $record->id]) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="Edit"><x-icon name="pencil" class="zi zi-sm" /></a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($records->hasPages())
                <div class="card-footer">{{ $records->links() }}</div>
            @endif
        @endif
    </div>
@endsection
