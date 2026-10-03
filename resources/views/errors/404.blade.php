{{-- A page with no route has no session, so it cannot show the builder's
     own error page. This one keeps its words and its look: the app's
     stylesheet and fonts need no session. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('There is nothing here') }} - {{ config('app.name') }}</title>

    {{-- The theme saved in this browser first, then the system's, as the app does. --}}
    <script>
        (function() {
            let appearance = 'system';

            try {
                appearance = localStorage.getItem('appearance') || 'system';
            } catch (e) {}

            document.documentElement.classList.toggle('dark', appearance === 'dark'
                || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches));
        })();
    </script>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    @fonts

    @vite(['resources/css/app.css'])
</head>
<body class="bg-background font-sans text-foreground antialiased">
    <header class="border-b">
        <div class="mx-auto flex h-14 max-w-7xl items-center px-4 sm:px-8">
            <a href="{{ url('/') }}" class="flex items-center gap-2 text-sm font-semibold">
                <span class="flex size-8 items-center justify-center rounded-md bg-foreground">
                    <span class="size-5 bg-background" style="mask: url(/favicon.svg) center / contain no-repeat"></span>
                </span>
                {{ config('app.name') }}
            </a>
        </div>
    </header>
    <main class="mx-auto max-w-7xl px-4 pt-20 pb-24 sm:px-8 sm:pt-32">
        <p class="text-sm text-muted-foreground">{{ __('Error 404') }}</p>
        <h1 class="mt-3 max-w-3xl font-display text-4xl leading-[1.05] font-medium tracking-[-0.035em] text-balance sm:text-6xl">
            {{ __('There is nothing here') }}.
            <span class="text-muted-foreground">{{ __('The link may be old, or the page was removed.') }}</span>
        </h1>
        <a href="{{ url('/') }}" class="mt-10 inline-flex min-h-11 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 sm:min-h-9">
            {{ __('Go to the home page') }}
        </a>
    </main>
</body>
</html>
