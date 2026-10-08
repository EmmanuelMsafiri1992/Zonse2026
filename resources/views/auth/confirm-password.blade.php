@extends('layouts.auth')
@section('title', 'Confirm password')
@section('content')
    <h3 class="mb-1">Confirm your password</h3>
    <p class="text-muted mb-4">This is a sensitive area. Please confirm your password to continue.</p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <x-form.input name="password" label="Password" type="password" required autofocus autocomplete="current-password" />
        <button type="submit" class="btn btn-primary w-100 btn-lg">Confirm</button>
    </form>
@endsection
