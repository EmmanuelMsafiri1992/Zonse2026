@extends('layouts.app')
@section('title', 'Appointments')
@section('content')
    <x-page-header title="Appointments" sub="Every booking in one list. Switch to the calendar to see the week at a glance." :crumbs="['Appointments']">
        <a href="{{ route('appointments.calendar') }}" class="btn btn-white"><x-icon name="calendar-days" /> Calendar</a>
        @can('create', \Modules\Appointments\Models\Appointment::class)
            <a href="{{ route('appointments.create') }}" class="btn btn-primary"><x-icon name="calendar-plus" /> New booking</a>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-stat label="Today" :value="$stats['today']" icon="sun" color="primary" :href="route('appointments.index', ['range' => 'today'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Next 7 days" :value="$stats['week']" icon="calendar-range" color="info" :href="route('appointments.index', ['range' => 'upcoming'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Completed this month" :value="$stats['completed']" icon="check-circle-2" color="success" :href="route('appointments.index', ['status' => 'completed', 'range' => 'all'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="No-shows this month" :value="$stats['no_show']" icon="user-x" color="danger" :href="route('appointments.index', ['status' => 'no_show', 'range' => 'all'])" /></div>
    </div>

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                @if($filters['contact'])<input type="hidden" name="contact" value="{{ $filters['contact'] }}">@endif
                <div class="z-search flex-grow-1" style="max-width:320px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search customer, service or notes…">
                </div>
                <select name="range" class="form-select w-auto" onchange="this.form.submit()">
                    @foreach(['upcoming' => 'Upcoming', 'today' => 'Today', 'past' => 'Past', 'all' => 'All dates'] as $key => $label)
                        <option value="{{ $key }}" @selected($filters['range'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    @foreach($statuses as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach
                </select>
                @if($staff->count() > 1)
                    <select name="staff" class="form-select w-auto" onchange="this.form.submit()">
                        <option value="">Anyone</option>
                        @foreach($staff as $id => $name)<option value="{{ $id }}" @selected((string) $filters['staff'] === (string) $id)>{{ $name }}</option>@endforeach
                    </select>
                @endif
                <button class="btn btn-soft-primary">Filter</button>
                @if($filters['q'] || $filters['status'] || $filters['staff'] || $filters['contact'] || $filters['range'] !== 'upcoming')
                    <a href="{{ route('appointments.index') }}" class="btn btn-link btn-sm text-muted">Clear</a>
                @endif
            </form>
            <span class="fs-8 text-muted">{{ $appointments->total() }} {{ \Illuminate\Support\Str::plural('booking', $appointments->total()) }}</span>
        </div>

        @if($appointments->isEmpty())
            <div class="card-body">
                <x-empty icon="calendar-check" title="No bookings here" text="{{ $filters['q'] || $filters['status'] ? 'Nothing matches your filters.' : 'Book a customer in for a service and it will show up here and on the calendar.' }}">
                    @can('create', \Modules\Appointments\Models\Appointment::class)
                        <a href="{{ route('appointments.create') }}" class="btn btn-primary"><x-icon name="calendar-plus" /> New booking</a>
                    @endcan
                </x-empty>
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>When</th><th>Customer</th><th>Service</th><th>With</th><th>Status</th><th class="text-end">Price</th><th></th></tr></thead>
                    <tbody>
                    @foreach($appointments as $a)
                        <tr>
                            <td>
                                <a href="{{ route('appointments.show', $a) }}" class="text-reset text-decoration-none">
                                    <span class="z-row-title d-block">{{ $a->starts_at->format('D d M Y') }}</span>
                                    <span class="z-row-sub">{{ $a->starts_at->format('H:i') }} – {{ $a->ends_at->format('H:i') }} · {{ $a->durationMinutes() }} min</span>
                                </a>
                            </td>
                            <td>
                                @if($a->contact)
                                    <a href="{{ route('contacts.show', $a->contact) }}" class="text-reset fw-600 fs-7">{{ $a->contact->displayName() }}</a>
                                    <div class="z-row-sub">{{ $a->contact->phone ?: $a->contact->email }}</div>
                                @else — @endif
                            </td>
                            <td class="fs-7">
                                @if($a->service)<span class="d-inline-block rounded-circle me-1" style="width:8px;height:8px;background:{{ $a->service->color }}"></span>@endif
                                {{ $a->displayTitle() }}
                            </td>
                            <td class="fs-7">{{ $a->staff?->name ?? 'Anyone' }}{{ $a->branch ? ' · '.$a->branch->name : '' }}</td>
                            <td><x-pill :status="$a->status">{{ $a->statusLabel() }}</x-pill></td>
                            <td class="text-end fs-7">{{ $a->price !== null ? $a->money($a->price) : '—' }}</td>
                            <td class="text-end">
                                <div class="z-row-actions d-inline-flex gap-1">
                                    <a href="{{ route('appointments.show', $a) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="View"><x-icon name="eye" class="zi zi-sm" /></a>
                                    @can('update', $a)
                                        <a href="{{ route('appointments.edit', $a) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="Edit"><x-icon name="pencil" class="zi zi-sm" /></a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($appointments->hasPages())
                <div class="card-footer">{{ $appointments->links() }}</div>
            @endif
        @endif
    </div>
@endsection
