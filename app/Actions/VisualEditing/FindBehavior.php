<?php

namespace App\Actions\VisualEditing;

use App\Actions\Previews\RunPreviewCommand;
use App\Models\Preview;
use App\Projects\ProjectRepository;
use App\VisualEditing\TemplateElement;
use App\VisualEditing\WayfinderCall;
use App\VisualEditing\WayfinderPaths;
use Illuminate\Support\Facades\Cache;

class FindBehavior
{
    public function __construct(private RunPreviewCommand $runPreviewCommand, private ProjectRepository $repository) {}

    /**
     * Get the behaviour a part starts: the server action it calls through
     * Wayfinder, as the app on show has it now (§ Selection context). The
     * key is the action's controller and method, or the route's name when
     * the route has no controller.
     *
     * There is no key, and never a guess, when the part calls nothing
     * through Wayfinder ("not_bound"). Nor is there when the call cannot be
     * matched to one route ("not_found"): the action was removed since the
     * file was written, the import is not where the app's Wayfinder writes,
     * or the app did not list its routes in time.
     *
     * @param  string  $file  The file the element is in, at the revision
     * @return array{key: string|null, route: string|null, reason: 'not_bound'|'not_found'|null}
     */
    public function handle(Preview $preview, string $revision, string $file, string $contents, TemplateElement $element): array
    {
        $call = WayfinderCall::in($contents, $element, $this->paths($preview, $revision), $file);

        if ($call === null) {
            return ['key' => null, 'route' => null, 'reason' => 'not_bound'];
        }

        if ($call->kind === 'unknown') {
            return ['key' => null, 'route' => null, 'reason' => 'not_found'];
        }

        $matches = array_values(array_filter($this->routes($preview), fn (array $route) => $call->kind === 'action'
            ? self::sameAction($route['action'], $call->name)
            : $route['name'] !== null && self::comparable($route['name']) === self::comparable($call->name)));

        // One action may answer more than one address, as a form's edit and
        // update; they are one behaviour when they name one action.
        $keys = array_values(array_unique(array_map(fn (array $route) => self::key($route), $matches)));

        if (count($keys) !== 1) {
            return ['key' => null, 'route' => null, 'reason' => 'not_found'];
        }

        return ['key' => $keys[0], 'route' => $matches[0]['name'], 'reason' => null];
    }

    /**
     * Read where the app's Wayfinder writes, from its own config files.
     */
    protected function paths(Preview $preview, string $revision): WayfinderPaths
    {
        $project = $preview->project;
        $vite = collect(['vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs'])
            ->map(fn (string $name) => $this->repository->show($project, $revision, $name))
            ->first(fn (?string $contents) => $contents !== null);

        return WayfinderPaths::read($this->repository->show($project, $revision, 'tsconfig.json'), $vite);
    }

    /**
     * Read the app's routes, as the framework on show lists them, with
     * those of its packages. The command runs beside the app on show, with
     * its own settings. An app that cannot list them in time has none to
     * match, so a slow app never holds up the part's details.
     *
     * @return list<array{name: string|null, action: string}>
     */
    protected function routes(Preview $preview): array
    {
        // The routes change only with the app's code.
        $output = Cache::remember("previews:{$preview->id}:{$preview->revision}:routes", now()->addMinutes(5), fn () => (string) rescue(
            fn () => $this->runPreviewCommand->handle($preview, ['php', 'artisan', 'route:list', '--json', '--no-interaction'], (int) config('builder.preview.routes_timeout'), ''),
            '',
            report: false,
        ));

        // Notices a package prints come before the JSON line.
        $line = collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '['));
        $routes = json_decode((string) $line, true);

        return array_values(array_map(
            fn (array $route) => ['name' => is_string($route['name'] ?? null) ? $route['name'] : null, 'action' => (string) $route['action']],
            array_filter(is_array($routes) ? $routes : [], fn ($route) => is_array($route) && is_string($route['action'] ?? null)),
        ));
    }

    /**
     * Whether a route's action is the call's: the same controller and the
     * same method, with an invokable controller listed with or without
     * "__invoke".
     */
    protected static function sameAction(string $action, string $call): bool
    {
        $action = (string) preg_replace('/@__invoke$/', '', ltrim($action, '\\'));

        return strcasecmp($action, $call) === 0;
    }

    /**
     * Get a route name as Wayfinder writes it in an export: "two-factor"
     * becomes "twoFactor", so both compare without dashes and case.
     */
    protected static function comparable(string $name): string
    {
        return strtolower(str_replace(['-', '_'], '', $name));
    }

    /**
     * @param  array{name: string|null, action: string}  $route
     */
    protected static function key(array $route): string
    {
        return $route['action'] === 'Closure' && $route['name'] !== null ? $route['name'] : ltrim($route['action'], '\\');
    }
}
