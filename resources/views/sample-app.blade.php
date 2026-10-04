<!DOCTYPE html>
<html lang="en" style="color-scheme: light dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Studio Classes</title>
    @vite('resources/js/sample-app.ts')
    <style type="text/tailwindcss">{!! $stylesheet !!}</style>
</head>
<body>
{!! $page !!}
<script data-builder-origin="{{ $origin }}">{!! $overlay !!}</script>
</body>
</html>
