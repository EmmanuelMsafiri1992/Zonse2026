<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
<div class="z-app">
    @include('partials.sidebar')

    <div class="z-main">
        @include('partials.topbar')

        <main class="z-content">
            @include('partials.flash')
            @yield('content')
        </main>

        <footer class="z-footer">
            <span>&copy; {{ date('Y') }} {{ config('app.name') }}. One platform, every profession.</span>
            <span>{{ $workspace?->name }}</span>
        </footer>
    </div>
</div>
<div class="z-backdrop" onclick="zonseo.closeSidebar()"></div>

@include('partials.new-workspace-modal')
@stack('modals')
@stack('scripts')
</body>
</html>
