<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @php $design ??= \Modules\Invoicing\Documents\DocumentDesign::for($document->workspace ?? $workspace); @endphp
    <title>@yield('title') · {{ $workspace?->name ?? config('app.name') }}</title>
    @include('partials.fonts')
    <style>
        :root { --ink: #323338; --muted: #676879; --line: #d0d4e4; --brand: {{ $design->color }}; --soft: {{ $design->tint(0.9) }}; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eceff8; color: var(--ink); font: 14px/1.5 "Figtree", -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .sheet { background: #fff; max-width: 820px; margin: 24px auto; padding: 40px 44px; border-radius: 8px; border: 1px solid var(--line); }
        .toolbar { max-width: 820px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; padding: 0 4px; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 4px; border: 1px solid var(--line); background: #fff; color: var(--ink); font-weight: 400; text-decoration: none; cursor: pointer; font-size: 13px; }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        .notice, .pay { max-width: 820px; margin: 16px auto 0; padding: 12px 16px; border-radius: 10px; font-size: 14px; }
        .notice-success { background: #ecfdf5; color: #047857; } .notice-info, .notice-warning { background: var(--soft); color: #1e40af; } .notice-danger { background: #fef2f2; color: #b91c1c; }
        .pay { background: #fff; border: 1px solid var(--line); display: flex; gap: 16px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .pay-title { font-weight: 700; font-size: 16px; } .pay-sub { font-size: 12px; color: var(--muted); }
        .pay-options { display: flex; gap: 12px; flex-wrap: wrap; } .pay-options form { margin: 0; max-width: 220px; } .pay-options .btn { font-size: 14px; padding: 10px 18px; }
        @media print { .notice, .pay { display: none; } }
        @media print { body { background: #fff; } .sheet { box-shadow: none; margin: 0; max-width: none; border-radius: 0; padding: 0; } .toolbar { display: none; } }
        @media (max-width: 640px) { .sheet { padding: 24px 18px; } .doc .parties td { display: block; width: 100% !important; padding: 0 0 16px; } .doc .totals-wrap td:first-child { display: none; } }
        @include('invoicing::partials.document-styles', ['design' => $design])
    </style>
</head>
<body>
<div class="toolbar">
    @yield('toolbar')
    @hasSection('pdf')<a href="@yield('pdf')" class="btn">Download PDF</a>@endif
    <button class="btn btn-primary" onclick="window.print()">Print</button>
</div>
@yield('notice')
<div class="sheet">
    @yield('content')
</div>
</body>
</html>
