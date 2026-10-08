@extends('layouts.auth')
@section('title', 'Invitation')
@section('content')
    <div class="text-center mb-4">
        <span class="z-avatar z-avatar-lg z-avatar-soft rounded-3 mx-auto mb-3">{{ $invitation->workspace->initials }}</span>
        <h3 class="mb-1">Join {{ $invitation->workspace->name }}</h3>
        <p class="text-muted">{{ $invitation->inviter?->name ?? 'A colleague' }} invited you to join as <strong>{{ $invitation->role }}</strong>.</p>
    </div>

    @if(! $valid)
        <div class="alert alert-warning">This invitation has expired or was already used. Ask the workspace admin to send a new one.</div>
    @elseif(auth()->check())
        @if(strcasecmp(auth()->user()->email, $invitation->email) !== 0)
            <div class="alert alert-light border fs-7">You are signed in as <strong>{{ auth()->user()->email }}</strong> but the invitation was sent to <strong>{{ $invitation->email }}</strong>. You can still accept it with this account.</div>
        @endif
        <form method="POST" action="{{ route('invitations.accept.store', $invitation->token) }}">
            @csrf
            <button class="btn btn-primary btn-lg w-100"><x-icon name="check" /> Accept invitation</button>
        </form>
        <form method="POST" action="{{ route('logout') }}" class="mt-2">
            @csrf
            <button class="btn btn-white w-100">Use a different account</button>
        </form>
    @else
        <a href="{{ route('register') }}" class="btn btn-primary btn-lg w-100 mb-2">Create an account to accept</a>
        <a href="{{ route('login') }}" class="btn btn-white w-100">I already have an account</a>
        <p class="text-muted fs-8 text-center mt-3">You'll be brought back here after signing in.</p>
    @endif
@endsection
