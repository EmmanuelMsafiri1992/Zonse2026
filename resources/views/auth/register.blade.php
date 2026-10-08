@extends('layouts.auth')
@section('title', 'Create account')
@section('content')
    <h3 class="mb-1">Create your account</h3>
    <p class="text-muted mb-4">Free to start. Pick the apps your profession needs in the next step.</p>

    <form method="POST" action="{{ route('register') }}">
        @csrf
        <x-form.input name="name" label="Your full name" required autofocus autocomplete="name" />
        <x-form.input name="email" label="Email address" type="email" :value="session('invitation.email')" required autocomplete="username" />
        <x-form.input name="password" label="Password" type="password" required autocomplete="new-password" help="At least 8 characters." />
        <x-form.input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        <button type="submit" class="btn btn-primary w-100 btn-lg mt-2">Create account</button>
    </form>

    <p class="text-center text-muted mt-4 fs-7">
        Already have an account? <a href="{{ route('login') }}">Sign in</a>
    </p>
@endsection
