<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use Closure;
use Illuminate\Support\Str;

/**
 * List the named routes of the part of the app a change works on, with
 * their method, address and middleware, so the test writer uses the app's
 * real route names instead of guessing them. Laravel lists them when the
 * app can run; otherwise they are read from the route files. Nothing here
 * asks a model, and a listing or file that cannot be read gives no routes.
 */
class ListAreaRoutes
{
    public function __construct(
        protected RunWorkspaceCommand $runWorkspaceCommand,
        protected WorkspaceManager $workspaces,
    ) {}

    /**
     * Get one line per named route whose code belongs to the area, up to
     * "max_area_routes".
     *
     * @param  Closure(list<string>): bool  $inArea  Given the files a route runs or is declared in
     * @return list<string>
     */
    public function handle(Workspace $workspace, Closure $inArea): array
    {
        $lines = [];

        foreach ($this->listed($workspace) ?? $this->declared($workspace) as $route) {
            if ($route['name'] === null || ! $inArea($route['files'])) {
                continue;
            }

            $middleware = $route['middleware'] === [] ? '' : ' (middleware: '.implode(', ', $route['middleware']).')';
            $lines[] = "{$route['method']} /".ltrim($route['uri'], '/')." is named {$route['name']}{$middleware}";
        }

        return array_slice(array_values(array_unique($lines)), 0, (int) config('builder.verification.written_first.max_area_routes'));
    }

    /**
     * Get the routes as Laravel lists them, or null when the app cannot.
     *
     * @return list<array{method: string, uri: string, name: ?string, middleware: list<string>, files: list<string>}>|null
     */
    protected function listed(Workspace $workspace): ?array
    {
        $listing = rescue(fn () => $this->runWorkspaceCommand->handle($workspace, ['php', 'artisan', 'route:list', '--json', '--except-vendor', '--no-ansi'], 60), null, report: false);
        $routes = $listing?->exit_code === 0 ? json_decode($listing->output, true) : null;

        if (! is_array($routes)) {
            return null;
        }

        $found = [];

        foreach ($routes as $route) {
            if (! is_array($route) || ! is_string($route['uri'] ?? null)) {
                continue;
            }

            $class = Str::before((string) ($route['action'] ?? ''), '@');

            $found[] = [
                'method' => Str::before((string) ($route['method'] ?? ''), '|HEAD'),
                'uri' => $route['uri'],
                'name' => is_string($route['name'] ?? null) ? $route['name'] : null,
                'middleware' => array_values(array_filter((array) ($route['middleware'] ?? []), 'is_string')),
                'files' => str_starts_with($class, 'App\\') ? [$this->path($class)] : [],
            ];
        }

        return $found;
    }

    /**
     * Read the routes the app's route files declare one by one. Groups and
     * resources are not followed, so only middleware named on the route
     * itself is known.
     *
     * @return list<array{method: string, uri: string, name: ?string, middleware: list<string>, files: list<string>}>
     */
    protected function declared(Workspace $workspace): array
    {
        $listing = rescue(fn () => $this->runWorkspaceCommand->handle($workspace, ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', 'routes'], 60), null, report: false);
        $files = array_filter(explode("\0", (string) $listing?->output), fn (string $path) => str_ends_with($path, '.php'));
        sort($files);
        $driver = $this->workspaces->driver($workspace->driver);
        $found = [];

        foreach ($files as $file) {
            $contents = (string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $file), '', report: false);
            preg_match_all('/^use\s+(App\\\\[\w\\\\]+)\s*;/m', $contents, $uses);
            $classes = collect($uses[1])->keyBy(fn (string $class) => class_basename($class));

            foreach (explode(';', $contents) as $statement) {
                if (preg_match('/(?:Route::|->)(get|post|put|patch|delete|options|any)\(\s*[\'"]([^\'"]*)[\'"]/', $statement, $verb, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $route = substr($statement, $verb[0][1]);
                preg_match('/->name\(\s*[\'"]([^\'"]+)[\'"]/', $route, $name);
                preg_match('/->middleware\(([^)]*)\)/', $route, $middleware);
                preg_match_all('/([\w\\\\]+)::class/', $route, $controllers);
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $middleware[1] ?? '', $named);

                $found[] = [
                    'method' => Str::upper($verb[1][0]),
                    'uri' => $verb[2][0],
                    'name' => $name[1] ?? null,
                    'middleware' => $named[1],
                    'files' => [$file, ...array_map(
                        fn (string $class) => $this->path($classes[ltrim($class, '\\')] ?? (str_contains($class, '\\') ? ltrim($class, '\\') : "App\\Http\\Controllers\\{$class}")),
                        $controllers[1],
                    )],
                ];
            }
        }

        return $found;
    }

    /**
     * Get the file of one of the app's classes, as Composer finds it.
     */
    protected function path(string $class): string
    {
        return 'app/'.str_replace('\\', '/', Str::after($class, 'App\\')).'.php';
    }
}
