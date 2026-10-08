{{-- Self-contained on purpose: no Vite assets, database or session, so it still renders when those are what broke. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @include('partials.fonts')
    <style>
        :root { --bg: #eceff8; --card: #fff; --text: #323338; --muted: #676879; --primary: #0073ea; --border: #d0d4e4; }
        @media (prefers-color-scheme: dark) { :root { --bg: #181b34; --card: #292f4c; --text: #d5d8df; --muted: #9699a6; --primary: #579bfc; --border: #4b4e69; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text);
            font-family: "Figtree", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.5; }
        .box { width: 100%; max-width: 440px; background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 36px 32px; text-align: center; }
        .brand { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: var(--text); text-decoration: none; margin-bottom: 28px; }
        .mark { width: 32px; height: 32px; border-radius: 6px; display: grid; place-items: center; background: var(--primary); color: #fff; font-weight: 700; }
        .code { font-family: "Poppins", sans-serif; font-size: 44px; font-weight: 600; color: var(--primary); letter-spacing: -1px; }
        h1 { font-family: "Poppins", sans-serif; font-weight: 500; font-size: 24px; margin: 4px 0 8px; }
        p { color: var(--muted); margin: 0 0 24px; }
        .actions { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 4px; border: 1px solid var(--border); color: var(--text); text-decoration: none; font-size: 14px; }
        .btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
    </style>
</head>
<body>
<main class="box">
    <a class="brand" href="{{ url('/') }}"><span class="mark">Z</span>{{ config('app.name') }}</a>
    <div class="code">@yield('code')</div>
    <h1>@yield('message')</h1>
    <p>@yield('hint', 'Something went wrong on our side. Please try again in a moment.')</p>
    <div class="actions">
        <a class="btn" href="javascript:history.back()">Go back</a>
        <a class="btn btn-primary" href="{{ url('/dashboard') }}">Go to dashboard</a>
    </div>
</main>
</body>
</html>
