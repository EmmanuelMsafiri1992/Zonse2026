<div class="card h-100">
    <div class="card-header">
        <h5 class="card-title"><x-icon name="life-buoy" class="zi me-1" /> Support queue</h5>
        <div class="d-flex gap-1">
            @can('create', \Modules\Helpdesk\Models\Ticket::class)<a href="{{ route('tickets.create') }}" class="btn btn-sm btn-primary"><x-icon name="plus" class="zi zi-sm" /> Ticket</a>@endcan
            <a href="{{ route('tickets.index') }}" class="btn btn-sm btn-soft-primary">Queue</a>
        </div>
    </div>
    <div class="card-body pb-0">
        <div class="row g-2">
            <div class="col-6"><div class="fs-8 text-muted text-uppercase">Open</div><div class="fs-5 fw-600">{{ $openCount }}</div></div>
            <div class="col-6"><div class="fs-8 text-muted text-uppercase">Unassigned</div><div class="fs-5 fw-600 {{ $unassignedCount ? 'text-danger' : '' }}">{{ $unassignedCount }}</div></div>
        </div>
    </div>
    @if($tickets->isEmpty())
        <div class="card-body"><x-empty icon="party-popper" title="Queue is clear" text="No open tickets right now." class="py-2" /></div>
    @else
        <div class="list-group list-group-flush mt-3">
            @foreach($tickets as $ticket)
                <a href="{{ route('tickets.show', $ticket) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <span class="mw-0 flex-grow-1">
                        <span class="d-block fw-600 fs-7 text-truncate">{{ $ticket->subject }}</span>
                        <span class="d-block fs-8 text-truncate text-muted">{{ $ticket->number }} · {{ $ticket->requesterName() }} · {{ $ticket->last_activity_at?->diffForHumans() }}</span>
                    </span>
                    <x-pill :status="$ticket->priority">{{ $ticket->priorityLabel() }}</x-pill>
                </a>
            @endforeach
        </div>
    @endif
</div>
