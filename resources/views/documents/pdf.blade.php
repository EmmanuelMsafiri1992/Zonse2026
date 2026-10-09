<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $heading ?: $template->name }}</title>
    @include('documents.partials.styles')
</head>
<body class="z-doc">
    @include('documents.partials.page')
</body>
</html>
