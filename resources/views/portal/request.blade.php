@extends('layouts.portal')
@section('title', $ticket->number)
@section('content')
    <a href="{{ route('portal.requests', $portalWorkspace) }}" class="fs-7 text-decoration-none">&larr; All requests</a>
    <div class="d-flex flex-wrap align-items-center gap-2 mt-2 mb-3">
        <h1 class="h4 mb-0 me-auto">{{ $ticket->subject }}</h1>
        <span class="fs-7 text-muted">{{ $ticket->number }}</span>
        <x-pill :status="$ticket->status">{{ $ticket->statusLabel() }}</x-pill>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="fs-8 text-muted mb-2">{{ $ticket->requesterName() }} · {{ $ticket->created_at->format('d M Y, H:i') }}</div>
            <div style="white-space: pre-line">{{ $ticket->body }}</div>
        </div>
    </div>

    @foreach($replies as $reply)
        <div class="card mb-3 {{ $reply->user_id ? 'border-primary-subtle' : '' }}">
            <div class="card-body">
                <div class="fs-8 text-muted mb-2">{{ $reply->user_id ? $reply->authorName().' · '.$portalWorkspace->name : $reply->authorName() }} · {{ $reply->created_at->format('d M Y, H:i') }}</div>
                <div style="white-space: pre-line">{{ $reply->body }}</div>
            </div>
        </div>
    @endforeach

    @if($ticket->status === 'closed')
        <p class="text-muted fs-7">This request is closed. <a href="{{ route('portal.requests', $portalWorkspace) }}">Open a new one</a> if you need more help.</p>
    @else
        <div class="card">
            <form method="POST" action="{{ route('portal.requests.reply', [$portalWorkspace, $ticket->id]) }}" class="card-body">
                @csrf
                <x-form.textarea name="body" label="Reply" required rows="4" />
                <button class="btn btn-primary"><x-icon name="send" /> Send reply</button>
            </form>
        </div>
    @endif
@endsection
