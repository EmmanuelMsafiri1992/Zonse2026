@extends('layouts.auth')
@section('title', 'Two-factor check')
@section('content')
    <div x-data="{ recovery: false }">
        <h3 class="mb-1">Two-factor authentication</h3>
        <p class="text-muted mb-4" x-show="!recovery">Enter the 6-digit code from your authenticator app.</p>
        <p class="text-muted mb-4" x-show="recovery" x-cloak>Enter one of your emergency recovery codes.</p>

        <form method="POST" action="{{ route('two-factor.login') }}">
            @csrf
            <div x-show="!recovery">
                <x-form.input name="code" label="Authentication code" inputmode="numeric" autocomplete="one-time-code" autofocus />
            </div>
            <div x-show="recovery" x-cloak>
                <x-form.input name="recovery_code" label="Recovery code" autocomplete="one-time-code" />
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg">Continue</button>
        </form>

        <p class="text-center mt-4 fs-7">
            <a href="#" x-on:click.prevent="recovery = !recovery" x-text="recovery ? 'Use an authentication code' : 'Use a recovery code'"></a>
        </p>
    </div>
@endsection
