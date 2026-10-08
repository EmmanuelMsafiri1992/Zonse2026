@extends('layouts.auth')
@section('title', 'Verify email')
@section('content')
    <h3 class="mb-1">Check your inbox</h3>
    <p class="text-muted mb-4">We sent a verification link to <strong>{{ auth()->user()->email }}</strong>. Click it to activate your account.</p>

    <form method="POST" action="{{ route('verification.send') }}" class="mb-3">
        @csrf
        <button type="submit" class="btn btn-primary w-100">Resend verification email</button>
    </form>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-white w-100">Sign out</button>
    </form>
@endsection
