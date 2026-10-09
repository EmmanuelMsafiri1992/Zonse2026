<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $heading ?: $template->name }}</title>
    @include('documents.partials.styles')
    <style>
        html { background: #e9ecef; }
        body.z-doc { margin: 16px auto; background: #fff; box-shadow: 0 1px 6px rgba(0, 0, 0, .15); position: relative;
            width: {{ $template->orientation === 'landscape' ? '1000px' : '720px' }}; min-height: {{ $template->orientation === 'landscape' ? '707px' : '1018px' }}; padding: 48px; box-sizing: border-box; }
        .z-frame { position: absolute; }
    </style>
</head>
<body class="z-doc">
    @include('documents.partials.page')
</body>
</html>
