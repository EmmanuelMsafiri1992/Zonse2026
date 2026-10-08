@extends('layouts.auth')
@section('title', 'Reset password')
@section('content')
    <h3 class="mb-1">Forgot your password?</h3>
    <p class="text-muted mb-4">Enter your email and we will send you a link to choose a new one.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <x-form.input name="email" label="Email address" type="email" required autofocus />
        <button type="submit" class="btn btn-primary w-100 btn-lg">Email reset link</button>
    </form>

    <p class="text-center text-muted mt-4 fs-7"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
