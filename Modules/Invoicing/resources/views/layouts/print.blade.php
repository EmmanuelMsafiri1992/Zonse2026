<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ $workspace?->name ?? config('app.name') }}</title>
    <style>
        :root { --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --brand: #2563eb; --soft: #eff6ff; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: var(--ink); font: 14px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .sheet { background: #fff; max-width: 820px; margin: 24px auto; padding: 40px 44px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
        .toolbar { max-width: 820px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; padding: 0 4px; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--line); background: #fff; color: var(--ink); font-weight: 600; text-decoration: none; cursor: pointer; font-size: 13px; }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        .head { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; }
        .brand img { max-height: 56px; max-width: 180px; }
        .brand h1 { font-size: 20px; margin: 0 0 4px; }
        .brand, .doc-meta { font-size: 13px; color: var(--muted); }
        .doc-title { text-align: right; }
        .doc-title h2 { margin: 0; font-size: 28px; letter-spacing: .04em; color: var(--brand); text-transform: uppercase; }
        .doc-title .num { font-size: 15px; font-weight: 600; color: var(--ink); }
        .status { display: inline-block; margin-top: 6px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; background: var(--soft); color: var(--brand); }
        .status.paid { background: #ecfdf5; color: #047857; } .status.overdue, .status.cancelled, .status.rejected, .status.expired { background: #fef2f2; color: #b91c1c; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 32px 0 24px; }
        .parties h4 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
        .parties p { margin: 0; white-space: pre-line; }
        .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; background: var(--soft); border-radius: 10px; padding: 12px 16px; font-size: 13px; }
        .meta b { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); border-bottom: 2px solid var(--line); padding: 8px 6px; }
        td { padding: 10px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .r { text-align: right; } .b { font-weight: 600; }
        .totals { margin-left: auto; width: 300px; margin-top: 16px; font-size: 14px; }
        .totals div { display: flex; justify-content: space-between; padding: 5px 6px; }
        .totals .grand { border-top: 2px solid var(--ink); font-size: 17px; font-weight: 700; margin-top: 4px; }
        .totals .due { background: var(--soft); border-radius: 8px; font-weight: 700; color: var(--brand); }
        .notes { margin-top: 32px; font-size: 13px; color: var(--muted); }
        .notes h4 { margin: 0 0 4px; color: var(--ink); font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
        .notes p { margin: 0 0 14px; white-space: pre-line; }
        .foot { margin-top: 32px; border-top: 1px solid var(--line); padding-top: 12px; font-size: 12px; color: var(--muted); text-align: center; }
        .notice, .pay { max-width: 820px; margin: 16px auto 0; padding: 12px 16px; border-radius: 10px; font-size: 14px; }
        .notice-success { background: #ecfdf5; color: #047857; } .notice-info, .notice-warning { background: var(--soft); color: #1e40af; } .notice-danger { background: #fef2f2; color: #b91c1c; }
        .pay { background: #fff; border: 1px solid var(--line); display: flex; gap: 16px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .pay-title { font-weight: 700; font-size: 16px; } .pay-sub { font-size: 12px; color: var(--muted); }
        .pay-options { display: flex; gap: 12px; flex-wrap: wrap; } .pay-options form { margin: 0; max-width: 220px; } .pay-options .btn { font-size: 14px; padding: 10px 18px; }
        @media print { .notice, .pay { display: none; } }
        @media print { body { background: #fff; } .sheet { box-shadow: none; margin: 0; max-width: none; border-radius: 0; padding: 0; } .toolbar { display: none; } }
        @media (max-width: 640px) { .sheet { padding: 24px 18px; } .parties, .meta { grid-template-columns: 1fr; } .totals { width: 100%; } }
    </style>
</head>
<body>
<div class="toolbar">
    @yield('toolbar')
    <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
</div>
@yield('notice')
<div class="sheet">
    @yield('content')
</div>
</body>
</html>
