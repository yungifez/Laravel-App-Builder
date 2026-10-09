<?php

namespace App\Previews;

use Illuminate\Support\Str;

/**
 * The pages a visitor can open in an app, read from what
 * `route:list --json` prints: each address that shows a page by itself,
 * without anything to fill in.
 */
class AppPages
{
    /**
     * First parts of addresses that Laravel and its packages answer with
     * something other than a page to look at.
     */
    protected const NOT_PAGES = ['up', 'storage', '.well-known', 'broadcasting', 'sanctum', 'livewire'];

    /**
     * Last parts of addresses that sign-in packages answer with data for
     * their own pages, not with a page.
     */
    protected const NOT_PAGE_ENDS = ['/options', '-status', '/two-factor-qr-code', '/two-factor-secret-key', '/two-factor-recovery-codes'];

    /**
     * Read the pages, home first, then by address.
     *
     * @return list<array{path: string, words: string, signed_in: bool}>
     */
    public static function in(string $output): array
    {
        // Notices a package prints come before the JSON line.
        $line = collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '['));
        $routes = json_decode((string) $line, true);

        return array_values(collect(is_array($routes) ? $routes : [])
            ->filter(fn ($route) => is_array($route) && is_string($route['uri'] ?? null) && is_string($route['method'] ?? null))
            ->filter(fn (array $route) => in_array('GET', explode('|', (string) $route['method']), true))
            // A page answers a browser, not another program.
            ->filter(fn (array $route) => in_array('web', (array) ($route['middleware'] ?? []), true))
            ->map(fn (array $route) => [
                // A part that may be left out is left out.
                'path' => '/'.trim((string) preg_replace('#/?\{[^}]+\?\}#', '', (string) $route['uri']), '/'),
                'signed_in' => in_array('auth', (array) ($route['middleware'] ?? []), true)
                    || collect((array) ($route['middleware'] ?? []))->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'auth:')),
            ])
            ->reject(fn (array $page) => str_contains($page['path'], '{') || self::notPage($page['path']))
            ->map(fn (array $page) => ['path' => $page['path'], 'words' => self::words($page['path']), 'signed_in' => $page['signed_in']])
            ->unique('path')
            ->sortBy(fn (array $page) => $page['path'] === '/' ? '' : $page['path'])
            ->all());
    }

    /**
     * Whether an address answers with something other than a page.
     */
    protected static function notPage(string $path): bool
    {
        $first = Str::before(ltrim($path, '/'), '/');

        // Tools such as a debug bar keep their addresses under "_".
        return str_starts_with($first, '_') || in_array($first, self::NOT_PAGES, true) || Str::endsWith($path, self::NOT_PAGE_ENDS);
    }

    /**
     * A page's name, from its address.
     */
    protected static function words(string $path): string
    {
        if ($path === '/') {
            return 'Home';
        }

        return Str::of($path)->trim('/')->replace(['/', '-', '_', '.'], ' ')->squish()->lower()->ucfirst()->toString();
    }
}
