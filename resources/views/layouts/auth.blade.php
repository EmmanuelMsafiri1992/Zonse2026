<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') · {{ config('app.name') }}</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
<div class="z-auth">
    <aside class="z-auth-side">
        <a href="{{ route('home') }}" class="d-inline-flex align-items-center gap-2 text-white text-decoration-none font-heading fw-600 fs-5">
            <span class="d-grid place-items-center rounded-3 bg-primary text-white fw-bold" style="width:36px;height:36px;display:grid;place-items:center">Z</span>
            {{ config('app.name') }}
        </a>
        <div class="position-relative" style="z-index:1">
            <h2 class="mb-3">Run your whole business<br>from one place.</h2>
            <p class="text-white-50 mb-4">Invoicing, bookings, patients, students, stock, staff, projects and more. Switch on only the apps your profession needs.</p>
            <div class="z-auth-feature"><x-icon name="check-circle-2" /> <span>300+ apps across 20 suites, one subscription</span></div>
            <div class="z-auth-feature"><x-icon name="check-circle-2" /> <span>Built for clinics, schools, shops, farms, churches, agencies, freelancers</span></div>
            <div class="z-auth-feature"><x-icon name="check-circle-2" /> <span>Multi-branch, multi-currency, roles and audit trail out of the box</span></div>
        </div>
        <div class="text-white-50 fs-8 position-relative" style="z-index:1">&copy; {{ date('Y') }} {{ config('app.name') }}</div>
    </aside>

    <section class="z-auth-form">
        <div class="z-auth-box">
            @include('partials.flash')
            @yield('content')
        </div>
    </section>
</div>
</body>
</html>
