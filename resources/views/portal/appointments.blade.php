@extends('layouts.portal')
@section('title', 'Appointments')
@section('content')
    <h1 class="h4 mb-3">Coming up</h1>
    <div class="card mb-4">
        @if($upcoming->isEmpty())
            <div class="card-body"><x-empty icon="calendar-days" title="No upcoming appointments" class="py-2" /></div>
        @else
            <ul class="list-group list-group-flush">
                @foreach($upcoming as $appointment)
                    <li class="list-group-item py-3">
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <div class="me-auto">
                                <div class="fw-600">{{ $appointment->displayTitle() }}</div>
                                <div class="fs-7 text-muted">{{ $appointment->starts_at->format('l d M Y, H:i') }}–{{ $appointment->ends_at->format('H:i') }}@if($appointment->staff) · with {{ $appointment->staff->name }}@endif</div>
                            </div>
                            <x-pill :status="$appointment->status">{{ $appointment->statusLabel() }}</x-pill>
                            <button class="btn btn-sm btn-white" type="button" data-bs-toggle="collapse" data-bs-target="#cancel-{{ $appointment->id }}">Cancel</button>
                        </div>
                        <form method="POST" action="{{ route('portal.appointments.cancel', [$portalWorkspace, $appointment->id]) }}" class="collapse mt-3" id="cancel-{{ $appointment->id }}">
                            @csrf
                            <x-form.input name="reason" label="Reason (optional)" maxlength="500" />
                            <button class="btn btn-sm btn-danger">Cancel this appointment</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if($past->isNotEmpty())
        <h2 class="h5 mb-3">Earlier</h2>
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Appointment</th><th>When</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($past as $appointment)
                            <tr>
                                <td>{{ $appointment->displayTitle() }}</td>
                                <td>{{ $appointment->starts_at->format('d M Y, H:i') }}</td>
                                <td><x-pill :status="$appointment->status">{{ $appointment->statusLabel() }}</x-pill></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
