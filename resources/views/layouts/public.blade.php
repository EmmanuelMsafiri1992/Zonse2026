<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · @endif{{ $publicWorkspace->name }}</title>
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @if($brandCss = app(\App\Support\Branding::class)->css($brand['color']))<style>{!! $brandCss !!}</style>@endif
</head>
<body class="bg-body-tertiary">
@php $publicLogo = $publicWorkspace->logo_url ?? $brand['logo_url']; @endphp
@unless($embed ?? false)
<header class="bg-white border-bottom">
    <div class="container py-3 d-flex align-items-center gap-3" style="max-width: 720px">
        <a href="{{ route('public.show', $publicWorkspace) }}" class="d-inline-flex align-items-center gap-2 text-decoration-none text-dark font-heading fw-600 fs-5 me-auto">
            @include('partials.brand-mark', ['brand' => ['logo_url' => $publicLogo, 'mark' => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($publicWorkspace->name, 0, 1))], 'class' => 'rounded-3 bg-primary text-white fw-bold'])
            {{ $publicWorkspace->name }}
        </a>
    </div>
</header>
@endunless

<main class="container {{ ($embed ?? false) ? 'py-2' : 'py-4' }}" style="max-width: 720px">
    @include('partials.flash')
    @yield('content')
</main>

@unless($embed ?? false)
<footer class="container pb-4 fs-8 text-muted text-center" style="max-width: 720px">
    &copy; {{ date('Y') }} {{ $publicWorkspace->name }}@unless($brand['hide_powered_by']) · Powered by {{ config('app.name') }}@endunless
</footer>
@endunless
</body>
</html>
