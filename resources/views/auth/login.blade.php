@extends('layouts.auth')
@section('title', 'Sign in')
@section('content')
    <h3 class="mb-1">Welcome back</h3>
    <p class="text-muted mb-4">Sign in to continue to your workspace.</p>

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <x-form.input name="email" label="Email address" type="email" required autofocus autocomplete="username" />
        <div class="mb-3">
            <div class="d-flex justify-content-between">
                <label for="f_password" class="form-label required">Password</label>
                @if(Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="fs-7">Forgot password?</a>
                @endif
            </div>
            <input type="password" name="password" id="f_password" class="form-control @error('password') is-invalid @enderror" required autocomplete="current-password">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="form-check mb-4">
            <input type="checkbox" name="remember" id="remember" class="form-check-input" value="1">
            <label for="remember" class="form-check-label">Keep me signed in</label>
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-lg">Sign in</button>
    </form>

    <p class="text-center text-muted mt-4 fs-7">
        New to {{ config('app.name') }}? <a href="{{ route('register') }}">Create a free account</a>
    </p>
@endsection
