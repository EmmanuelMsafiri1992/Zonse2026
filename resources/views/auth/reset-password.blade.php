@extends('layouts.auth')
@section('title', 'Choose a new password')
@section('content')
    <h3 class="mb-1">Choose a new password</h3>
    <p class="text-muted mb-4">Make it long and hard to guess.</p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-form.input name="email" label="Email address" type="email" :value="$request->email" required />
        <x-form.input name="password" label="New password" type="password" required autofocus autocomplete="new-password" />
        <x-form.input name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />
        <button type="submit" class="btn btn-primary w-100 btn-lg">Save new password</button>
    </form>
@endsection
