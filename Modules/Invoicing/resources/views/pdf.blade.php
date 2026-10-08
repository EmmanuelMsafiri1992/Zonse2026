<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $design->title($kind) }} {{ $document->number }}</title>
    <style>
        @page { margin: 32px 36px; }
        body { margin: 0; }
        @include('invoicing::partials.document-styles')
    </style>
</head>
<body>
    @include('invoicing::partials.document-print')
</body>
</html>
