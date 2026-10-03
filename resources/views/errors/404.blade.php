{{-- A page with no route has no session, so it cannot show the builder's
     own error page. This one keeps the same words without it. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('There is nothing here') }} - {{ config('app.name') }}</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #fafafa; color: #0a0a0a; }
        main { max-width: 42rem; padding: 6rem 1rem; margin: 0 auto; }
        p.code { font-size: .875rem; color: #737373; margin: 0; }
        h1 { font-size: 2.5rem; line-height: 1.05; margin: .5rem 0 0; font-weight: 600; letter-spacing: -.02em; }
        p.line { font-size: 1.125rem; color: #737373; }
        a { display: inline-block; margin-top: 1.5rem; padding: .5rem 1rem; border-radius: .375rem; background: #2563eb; color: #fff; text-decoration: none; font-size: .875rem; }
        @media (prefers-color-scheme: dark) {
            body { background: #0a0a0a; color: #fafafa; }
            p.code, p.line { color: #a3a3a3; }
        }
    </style>
</head>
<body>
    <main>
        <p class="code">{{ __('Error 404') }}</p>
        <h1>{{ __('There is nothing here') }}</h1>
        <p class="line">{{ __('The link may be old, or the page was removed.') }}</p>
        <a href="{{ url('/') }}">{{ __('Go to the home page') }}</a>
    </main>
</body>
</html>
