<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Pick the theme before the first paint, the same way the app does
             once it starts: the choice saved in this browser first, then the
             system's. The cookie alone can disagree with it, which painted a
             light page that turned dark a moment later. --}}
        <script>
            (function() {
                let appearance = '{{ $appearance ?? "system" }}';

                try {
                    appearance = localStorage.getItem('appearance') || 'system';
                } catch (e) {}

                const dark = appearance === 'dark'
                    || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>

        {{-- The page's own background from app.css, so the first paint is not a different shade. --}}
        <style>
            html {
                background-color: hsl(220 20% 98.5%);
            }

            html.dark {
                background-color: hsl(224 16% 7%);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
            <meta name="description" data-inertia="description" content="Don't just build a prototype. Every change passes fixed checks before you keep it.">
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
