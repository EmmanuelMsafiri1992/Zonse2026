@extends('layouts.public')
@section('title', 'Your booking')
@section('content')
    <div class="card">
        <div class="card-body p-4 text-center">
            <x-icon :name="$appointment->status === 'cancelled' ? 'calendar-x' : 'calendar-check'" class="zi zi-lg text-primary mb-2" />
            <h1 class="h4 mb-1">{{ $appointment->displayTitle() }}</h1>
            <div class="fs-5 mb-2">{{ $appointment->starts_at->format('l d M Y') }} · {{ $appointment->starts_at->format('H:i') }}–{{ $appointment->ends_at->format('H:i') }}</div>
            <x-pill :status="$appointment->status">{{ $appointment->status === 'scheduled' ? 'Waiting for confirmation' : $appointment->statusLabel() }}</x-pill>
            @if($appointment->price > 0)<div class="text-muted fs-7 mt-2">{{ $appointment->money($appointment->price) }}</div>@endif
            @if($cancellable)
                <form method="POST" action="{{ route('public.booking.cancel', [$publicWorkspace, $appointment->uuid]) }}" class="mt-3" onsubmit="return confirm('Cancel this booking?')">
                    @csrf
                    <button class="btn btn-white btn-sm text-danger"><x-icon name="calendar-x" class="zi zi-sm" /> Cancel booking</button>
                </form>
            @endif
        </div>
    </div>
    <div class="text-center mt-3"><a href="{{ route('public.show', $publicWorkspace) }}" class="fs-7">Back to {{ $publicWorkspace->name }}</a></div>
@endsection
