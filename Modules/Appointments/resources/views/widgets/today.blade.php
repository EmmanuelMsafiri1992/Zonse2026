<div class="card h-100">
    <div class="card-header">
        <h5 class="card-title"><x-icon name="calendar-check" class="zi me-1" /> Today's bookings</h5>
        <div class="d-flex gap-1">
            @can('create', \Modules\Appointments\Models\Appointment::class)<a href="{{ route('appointments.create') }}" class="btn btn-sm btn-primary"><x-icon name="plus" class="zi zi-sm" /> Book</a>@endcan
            <a href="{{ route('appointments.calendar') }}" class="btn btn-sm btn-soft-primary">Calendar</a>
        </div>
    </div>
    <div class="card-body pb-0">
        <div class="row g-2">
            <div class="col-6"><div class="fs-8 text-muted text-uppercase">Today</div><div class="fs-5 fw-600">{{ $todayCount }}</div></div>
            <div class="col-6"><div class="fs-8 text-muted text-uppercase">Next 7 days</div><div class="fs-5 fw-600">{{ $weekCount }}</div></div>
        </div>
    </div>
    @if($today->isEmpty())
        <div class="card-body"><x-empty icon="coffee" title="Nothing booked today" text="Upcoming bookings appear here on the day." class="py-2" /></div>
    @else
        <div class="list-group list-group-flush mt-3">
            @foreach($today as $a)
                <a href="{{ route('appointments.show', $a) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <span class="fw-600 fs-7 text-nowrap" style="min-width:44px">{{ $a->starts_at->format('H:i') }}</span>
                    <span class="mw-0 flex-grow-1">
                        <span class="d-block fw-600 fs-7 text-truncate">{{ $a->contact?->displayName() }}</span>
                        <span class="d-block fs-8 text-muted text-truncate">{{ $a->displayTitle() }}{{ $a->staff ? ' · '.$a->staff->name : '' }}</span>
                    </span>
                    <x-pill :status="$a->status">{{ $a->statusLabel() }}</x-pill>
                </a>
            @endforeach
        </div>
    @endif
</div>
