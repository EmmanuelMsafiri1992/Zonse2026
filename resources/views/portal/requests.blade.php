@extends('layouts.portal')
@section('title', 'Support requests')
@section('content')
    <div class="row g-4">
        <div class="col-lg-7">
            <h1 class="h4 mb-3">Your requests</h1>
            <div class="card">
                @if($tickets->isEmpty())
                    <div class="card-body"><x-empty icon="life-buoy" title="No requests yet" text="Ask a question or report a problem using the form." class="py-2" /></div>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($tickets as $ticket)
                            <a href="{{ route('portal.requests.show', [$portalWorkspace, $ticket->id]) }}" class="list-group-item list-group-item-action py-3 d-flex align-items-center gap-3">
                                <div class="me-auto">
                                    <div class="fw-600">{{ $ticket->subject }}</div>
                                    <div class="fs-8 text-muted">{{ $ticket->number }} · updated {{ $ticket->last_activity_at?->diffForHumans() }}</div>
                                </div>
                                <x-pill :status="$ticket->status">{{ $ticket->statusLabel() }}</x-pill>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h2 class="card-title h6 mb-0">New request</h2></div>
                <form method="POST" action="{{ route('portal.requests.store', $portalWorkspace) }}" class="card-body">
                    @csrf
                    <x-form.input name="subject" label="Subject" required maxlength="190" />
                    <x-form.textarea name="body" label="How can we help?" required rows="5" />
                    <button class="btn btn-primary"><x-icon name="send" /> Send request</button>
                </form>
            </div>
        </div>
    </div>
@endsection
