@extends('layouts.app')
@section('title', 'Tickets')
@section('content')
    <x-page-header title="Helpdesk" sub="Every question, complaint and request from your customers, in one queue." :crumbs="['Helpdesk']">
        @can('create', \Modules\Helpdesk\Models\Ticket::class)
            <a href="{{ route('tickets.create') }}" class="btn btn-primary"><x-icon name="plus" /> New ticket</a>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-stat label="Open" :value="$stats['open']" icon="inbox" color="primary" :href="route('tickets.index', ['status' => 'open'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Unassigned" :value="$stats['unassigned']" icon="user-x" color="danger" :href="route('tickets.index', ['assignee' => 'unassigned'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Waiting on customer" :value="$stats['pending']" icon="hourglass" color="warning" :href="route('tickets.index', ['status' => 'pending'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Resolved this week" :value="$stats['resolved_week']" icon="check-check" color="success" :href="route('tickets.index', ['status' => 'resolved'])" /></div>
    </div>

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                @if($filters['contact'])<input type="hidden" name="contact" value="{{ $filters['contact'] }}">@endif
                <div class="z-search flex-grow-1" style="max-width:300px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search tickets…">
                </div>
                <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    @foreach($statuses as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach
                    <option value="all" @selected($filters['status'] === 'all')>Everything</option>
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
                @if($filters['q'] || $filters['priority'] || $filters['assignee'] || $filters['contact'] || $filters['status'] !== 'active')
                    <a href="{{ route('tickets.index') }}" class="btn btn-link btn-sm text-muted">Clear</a>
                @endif
            </form>
            <span class="fs-8 text-muted">{{ $tickets->total() }} {{ \Illuminate\Support\Str::plural('ticket', $tickets->total()) }}</span>
        </div>

        @if($tickets->isEmpty())
            <div class="card-body">
                <x-empty icon="life-buoy" title="Queue is clear" text="{{ $filters['q'] || $filters['priority'] || $filters['assignee'] || $filters['status'] !== 'active' ? 'Nothing matches your filters.' : 'Log a call, email or walk-in as a ticket so nothing gets forgotten.' }}">
                    @can('create', \Modules\Helpdesk\Models\Ticket::class)
                        <a href="{{ route('tickets.create') }}" class="btn btn-primary"><x-icon name="plus" /> New ticket</a>
                    @endcan
                </x-empty>
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>Ticket</th><th>Requester</th><th>Priority</th><th>Assignee</th><th>Status</th><th>Last activity</th><th></th></tr></thead>
                    <tbody>
                    @foreach($tickets as $ticket)
                        <tr class="{{ $ticket->isActive() ? '' : 'opacity-75' }}">
                            <td>
                                <a href="{{ route('tickets.show', $ticket) }}" class="text-reset text-decoration-none">
                                    <span class="z-row-title d-block">{{ $ticket->subject }}</span>
                                    <span class="z-row-sub">{{ $ticket->number }} · {{ $ticket->channelLabel() }}{{ $ticket->category ? ' · '.$ticket->category : '' }}</span>
                                </a>
                            </td>
                            <td class="fs-7">
                                @if($ticket->contact)
                                    <a href="{{ route('contacts.show', $ticket->contact) }}">{{ $ticket->contact->displayName() }}</a>
                                @else
                                    {{ $ticket->requesterName() }}
                                @endif
                            </td>
                            <td><x-pill :status="$ticket->priority">{{ $ticket->priorityLabel() }}</x-pill></td>
                            <td class="fs-7 {{ $ticket->assignee ? '' : 'text-danger' }}">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
                            <td><x-pill :status="$ticket->status">{{ $ticket->statusLabel() }}</x-pill></td>
                            <td class="fs-7 text-muted">{{ $ticket->last_activity_at?->diffForHumans() }}</td>
                            <td class="text-end">
                                <div class="z-row-actions d-inline-flex gap-1">
                                    <a href="{{ route('tickets.show', $ticket) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="View"><x-icon name="eye" class="zi zi-sm" /></a>
                                    @can('update', $ticket)
                                        <a href="{{ route('tickets.edit', $ticket) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="Edit"><x-icon name="pencil" class="zi zi-sm" /></a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($tickets->hasPages())
                <div class="card-footer">{{ $tickets->links() }}</div>
            @endif
        @endif
    </div>
@endsection
