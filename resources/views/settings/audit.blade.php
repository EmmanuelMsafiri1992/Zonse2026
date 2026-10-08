@extends('layouts.app')
@section('title', 'Audit log')
@section('content')
    <x-page-header title="Audit log" sub="Who did what, and when: sign-ins, settings and team changes, and every change to your records." :crumbs="['Settings' => route('settings.workspace.edit'), 'Audit log']">
        <a href="{{ route('settings.audit.export', request()->query()) }}" class="btn btn-white"><x-icon name="download" /> Export CSV</a>
        <a href="{{ route('settings.data-export.index') }}" class="btn btn-white"><x-icon name="archive" /> Export all data</a>
    </x-page-header>

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                <div class="z-search flex-grow-1" style="max-width:300px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Search descriptions…">
                </div>
                <select name="category" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    @foreach($categories as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="user" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">Everyone</option>
                    @foreach($members as $id => $name)
                        <option value="{{ $id }}" @selected((string) ($filters['user'] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control w-auto" aria-label="From">
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control w-auto" aria-label="To">
                <button class="btn btn-soft-primary">Filter</button>
                @if(array_filter($filters))
                    <a href="{{ route('settings.audit.index') }}" class="btn btn-link btn-sm text-muted">Clear</a>
                @endif
            </form>
            <span class="fs-8 text-muted">{{ $activities->total() }} {{ \Illuminate\Support\Str::plural('entry', $activities->total()) }}</span>
        </div>

        @if($activities->isEmpty())
            <div class="card-body">
                <x-empty icon="scroll-text" title="Nothing logged yet" text="{{ array_filter($filters) ? 'No entries match these filters.' : 'Sign-ins, settings changes and record changes will appear here.' }}" />
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>When</th><th>What happened</th><th>Category</th><th>By</th><th>IP address</th></tr></thead>
                    <tbody>
                    @foreach($activities as $a)
                        <tr>
                            <td class="fs-7 text-nowrap" title="{{ $a->created_at->toDayDateTimeString() }}">
                                {{ $a->created_at->format('d M Y, H:i') }}
                                <div class="z-row-sub">{{ $a->created_at->diffForHumans() }}</div>
                            </td>
                            <td>
                                @if(! empty($a->properties['url']))
                                    <a href="{{ $a->properties['url'] }}" class="z-row-title">{{ $a->description }}</a>
                                @else
                                    <span class="z-row-title">{{ $a->description }}</span>
                                @endif
                                @if(! empty($a->properties['changed']))
                                    <div class="z-row-sub">Changed: {{ implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c), $a->properties['changed'])) }}</div>
                                @endif
                            </td>
                            <td><span class="z-chip">{{ \App\Support\Audit::categoryLabel($a->log_name) }}</span></td>
                            <td class="fs-7">{{ $a->causer?->name ?? 'System' }}</td>
                            <td class="fs-7 text-muted">{{ $a->properties['ip'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($activities->hasPages())
                <div class="card-footer">{{ $activities->links() }}</div>
            @endif
        @endif
    </div>
@endsection
