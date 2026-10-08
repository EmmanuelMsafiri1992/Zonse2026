@extends('layouts.app')
@section('title', $appointment->displayTitle())
@section('content')
    <x-page-header :title="$appointment->displayTitle()" :sub="$appointment->starts_at->format('l d M Y').' · '.$appointment->starts_at->format('H:i').' – '.$appointment->ends_at->format('H:i').' · '.$appointment->durationMinutes().' min'"
                   :crumbs="['Appointments' => route('appointments.index'), $appointment->displayTitle()]">
        @can('changeStatus', $appointment)
            @foreach($transitions as $next)
                @if($next === 'cancelled')
                    <button type="button" class="btn btn-soft-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><x-icon name="x-circle" /> Cancel booking</button>
                @else
                    <form method="POST" action="{{ route('appointments.status', $appointment) }}">
                        @csrf <input type="hidden" name="status" value="{{ $next }}">
                        <button class="btn {{ $next === 'completed' ? 'btn-primary' : ($next === 'no_show' ? 'btn-soft-warning' : 'btn-white') }}">
                            <x-icon :name="['confirmed' => 'check-circle-2', 'completed' => 'check-check', 'no_show' => 'user-x', 'scheduled' => 'rotate-ccw'][$next] ?? 'circle'" />
                            {{ ['confirmed' => 'Confirm', 'completed' => 'Mark completed', 'no_show' => 'No-show', 'scheduled' => 'Reopen'][$next] ?? ucfirst($next) }}
                        </button>
                    </form>
                @endif
            @endforeach
        @endcan
        @can('update', $appointment)
            <a href="{{ route('appointments.edit', $appointment) }}" class="btn btn-white"><x-icon name="pencil" /> Edit</a>
        @endcan
        @can('delete', $appointment)
            <form method="POST" action="{{ route('appointments.destroy', $appointment) }}" onsubmit="return confirm('Delete this booking? This cannot be undone.')">
                @csrf @method('DELETE')
                <button class="btn btn-soft-danger btn-icon" title="Delete"><x-icon name="trash-2" /></button>
            </form>
        @endcan
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <x-pill :status="$appointment->status">{{ $appointment->statusLabel() }}</x-pill>
                        @if($appointment->service)<span class="fs-8 text-muted"><span class="d-inline-block rounded-circle me-1" style="width:8px;height:8px;background:{{ $appointment->service->color }}"></span>{{ $appointment->service->name }}</span>@endif
                    </div>
                    @if($appointment->status === 'cancelled' && $appointment->cancel_reason)
                        <div class="alert alert-danger fs-8">Cancelled: {{ $appointment->cancel_reason }}</div>
                    @endif
                    <ul class="list-unstyled fs-7 mb-0 d-grid gap-2">
                        <li class="d-flex gap-2"><x-icon name="user-round" class="zi zi-sm text-muted" />
                            <span>@if($appointment->contact)<a href="{{ route('contacts.show', $appointment->contact) }}">{{ $appointment->contact->displayName() }}</a><br><span class="text-muted">{{ $appointment->contact->phone ?: $appointment->contact->email }}</span>@else —@endif</span>
                        </li>
                        <li class="d-flex gap-2"><x-icon name="calendar" class="zi zi-sm text-muted" /> {{ $appointment->starts_at->format('D d M Y') }}</li>
                        <li class="d-flex gap-2"><x-icon name="clock" class="zi zi-sm text-muted" /> {{ $appointment->starts_at->format('H:i') }} – {{ $appointment->ends_at->format('H:i') }} ({{ $appointment->durationMinutes() }} min)</li>
                        <li class="d-flex gap-2"><x-icon name="users" class="zi zi-sm text-muted" /> {{ $appointment->staff?->name ?? 'Anyone available' }}</li>
                        <li class="d-flex gap-2"><x-icon name="map-pin" class="zi zi-sm text-muted" /> {{ $appointment->branch?->name ?? 'Any branch' }}</li>
                        <li class="d-flex gap-2"><x-icon name="banknote" class="zi zi-sm text-muted" /> {{ $appointment->price !== null ? $appointment->money($appointment->price) : 'No price set' }}</li>
                        <li class="d-flex gap-2 text-muted"><x-icon name="info" class="zi zi-sm" /> Booked {{ $appointment->created_at?->diffForHumans() }}{{ $appointment->creator ? ' by '.$appointment->creator->name : '' }}</li>
                    </ul>
                </div>
                @if($appointment->contact && Route::has('invoices.create') && $workspace->hasModule('invoicing') && $appointment->status === 'completed')
                    <div class="card-footer"><a href="{{ route('invoices.create', ['contact' => $appointment->contact_id]) }}" class="btn btn-sm btn-soft-primary w-100"><x-icon name="file-text" class="zi zi-sm" /> Invoice this visit</a></div>
                @endif
            </div>

            @if($appointment->notes)
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Booking notes</h5></div>
                    <div class="card-body fs-7" style="white-space:pre-line">{{ $appointment->notes }}</div>
                </div>
            @endif
            <x-custom-fields.details :record="$appointment" />
        </div>

        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Internal notes & activity</h5></div>
                @can('update', $appointment)
                    <form method="POST" action="{{ route('appointments.comments.store', $appointment) }}" class="card-body border-bottom">
                        @csrf
                        <x-form.textarea name="body" placeholder="Outcome, follow-up, anything for the team…" rows="2" required />
                        <button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> Add note</button>
                    </form>
                @endcan
                <div class="card-body">
                    @if($appointment->comments->isEmpty())
                        <x-empty icon="message-square" title="No notes yet" class="py-3" />
                    @else
                        <div class="z-timeline">
                            @foreach($appointment->comments as $note)
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

            @if($history->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h5 class="card-title">Other visits by {{ $appointment->contact?->displayName() }}</h5><a href="{{ route('appointments.index', ['contact' => $appointment->contact_id]) }}" class="btn btn-sm btn-soft-primary">All</a></div>
                    <div class="list-group list-group-flush">
                        @foreach($history as $h)
                            <a href="{{ route('appointments.show', $h) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                                <span class="flex-grow-1 fs-7">{{ $h->starts_at->format('D d M Y, H:i') }} <span class="text-muted">· {{ $h->displayTitle() }}</span></span>
                                <x-pill :status="$h->status">{{ $h->statusLabel() }}</x-pill>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    @can('changeStatus', $appointment)
        <div class="modal fade" id="cancelModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('appointments.status', $appointment) }}">
                    @csrf <input type="hidden" name="status" value="cancelled">
                    <div class="modal-header"><h5 class="modal-title">Cancel this booking</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <x-form.input name="reason" label="Reason (optional)" placeholder="Customer asked to reschedule…" />
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-white" data-bs-dismiss="modal">Keep it</button>
                        <button class="btn btn-danger"><x-icon name="x-circle" /> Cancel booking</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
