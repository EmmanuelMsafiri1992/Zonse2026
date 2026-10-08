@extends('layouts.app')
@section('title', 'Calendar')
@section('content')
    <x-page-header title="Calendar" :sub="$weekStart->format('d M').' – '.$weekEnd->format('d M Y')" :crumbs="['Appointments' => route('appointments.index'), 'Calendar']">
        <div class="btn-group">
            <a href="{{ route('appointments.calendar', array_filter(['week' => $previousWeek, 'staff' => $staffId])) }}" class="btn btn-white btn-icon" title="Previous week"><x-icon name="chevron-left" /></a>
            <a href="{{ route('appointments.calendar', array_filter(['staff' => $staffId])) }}" class="btn btn-white">This week</a>
            <a href="{{ route('appointments.calendar', array_filter(['week' => $nextWeek, 'staff' => $staffId])) }}" class="btn btn-white btn-icon" title="Next week"><x-icon name="chevron-right" /></a>
        </div>
        @if($staff->count() > 1)
            <form method="GET">
                @if(request('week'))<input type="hidden" name="week" value="{{ request('week') }}">@endif
                <select name="staff" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">Everyone</option>
                    @foreach($staff as $id => $name)<option value="{{ $id }}" @selected((string) $staffId === (string) $id)>{{ $name }}</option>@endforeach
                </select>
            </form>
        @endif
        <a href="{{ route('appointments.index') }}" class="btn btn-white"><x-icon name="list" /> List</a>
        @can('create', \Modules\Appointments\Models\Appointment::class)
            <a href="{{ route('appointments.create') }}" class="btn btn-primary"><x-icon name="calendar-plus" /> New booking</a>
        @endcan
    </x-page-header>

    <div class="card">
        <div class="card-body p-2">
            <div class="row g-2">
                @foreach($days as $day)
                    <div class="col-12 col-md-6 col-xl">
                        <div class="z-cal-day rounded-3 border h-100 {{ $day['isToday'] ? 'border-primary' : '' }} {{ $day['isWorking'] ? '' : 'bg-light' }}">
                            <div class="d-flex align-items-center justify-content-between px-2 py-2 border-bottom">
                                <div>
                                    <div class="fs-8 text-uppercase text-muted">{{ $day['date']->format('D') }}</div>
                                    <div class="fw-600 {{ $day['isToday'] ? 'text-primary' : '' }}">{{ $day['date']->format('d M') }}</div>
                                </div>
                                @can('create', \Modules\Appointments\Models\Appointment::class)
                                    <a href="{{ route('appointments.create', array_filter(['date' => $day['date']->format('Y-m-d'), 'staff' => $staffId])) }}" class="btn btn-sm btn-icon btn-soft-primary" title="Book on {{ $day['date']->format('D d M') }}"><x-icon name="plus" class="zi zi-sm" /></a>
                                @endcan
                            </div>
                            <div class="p-2 d-grid gap-2" style="min-height:140px">
                                @forelse($day['appointments'] as $a)
                                    <a href="{{ route('appointments.show', $a) }}" class="z-cal-item d-block rounded-2 px-2 py-1 text-decoration-none text-reset border-start border-3 {{ $a->status === 'cancelled' ? 'opacity-50 text-decoration-line-through' : '' }}"
                                       style="border-color: {{ $a->service?->color ?? '#94a3b8' }} !important; background: {{ ($a->service?->color ?? '#94a3b8') }}14">
                                        <div class="fs-8 fw-600">{{ $a->starts_at->format('H:i') }} – {{ $a->ends_at->format('H:i') }}</div>
                                        <div class="fs-7 text-truncate">{{ $a->contact?->displayName() }}</div>
                                        <div class="fs-8 text-muted text-truncate">{{ $a->displayTitle() }}{{ $a->staff ? ' · '.$a->staff->name : '' }}</div>
                                    </a>
                                @empty
                                    <div class="fs-8 text-muted text-center pt-4">{{ $day['isWorking'] ? 'Free' : 'Closed' }}</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="card-footer fs-8 text-muted d-flex flex-wrap gap-3">
            <span>Hours {{ $settings['day_start'] }} – {{ $settings['day_end'] }}</span>
            <span>Slots of {{ $settings['slot_minutes'] }} min</span>
            @can('manage-workspace')<a href="{{ route('settings.appointments.edit') }}">Change booking settings</a>@endcan
        </div>
    </div>
@endsection
