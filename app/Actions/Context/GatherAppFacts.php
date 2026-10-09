<?php

namespace App\Actions\Context;

use App\Actions\Workspaces\CheckStepNeeds;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Features\AppTraces;
use App\Features\TestMap;
use App\Models\Project;
use App\Models\TestObservation;
use App\Projects\ProjectRepository;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Throwable;

/**
 * Explore an app without a model: what its code holds, and what its own
 * tests touch when they run. The notes drafter then words these facts for
 * the owner; it does not have to guess them.
 */
class GatherAppFacts
{
    /**
     * The most characters of facts given to the drafter, so the cost the
     * owner was told stays the cost.
     */
    public const MAX_CHARS = 24_000;

    /**
     * Where the app's routes are written in the workspace.
     */
    protected const ROUTES_FILE = 'storage/logs/explore-routes.json';

    public function __construct(
        private ProjectRepository $repository,
        private ProvisionWorkspace $provisionWorkspace,
        private RunWorkspaceCommand $runWorkspaceCommand,
        private DestroyWorkspace $destroyWorkspace,
        private WorkspaceManager $workspaces,
        private CheckStepNeeds $needs,
    ) {}

    /**
     * Gather the facts about the app at one commit. What cannot run, such
     * as tests that fail to start, is said in the facts, not thrown.
     *
     * @param  list<string>  $files
     * @return array{text: string, map: TestMap|null, pages: list<array{route: string, files: list<string>}>}
     */
    public function handle(Project $project, string $commit, array $files): array
    {
        $ran = $this->run($project, $commit);
        $lines = [
            ...$this->code($project, $commit, $files),
            ...$this->routes($ran['routes']),
            ...$this->tests($ran['map'], $ran['error']),
            ...$this->pageLines($ran['pages']),
        ];

        return [
            'text' => Str::limit(implode("\n", $lines), self::MAX_CHARS, "\n…"),
            'map' => $ran['map'],
            'pages' => $ran['pages'],
        ];
    }

    /**
     * Run the app in a workspace of its own: its routes as the framework
     * lists them, and its tests with code coverage and the recorder on.
     * The test map is kept, so the owner's page shows what checks each
     * part from now on.
     *
     * @return array{routes: list<array<string, mixed>>, map: TestMap|null, pages: list<array{route: string, files: list<string>}>, error: string|null}
     */
    protected function run(Project $project, string $commit): array
    {
        $workspace = null;

        try {
            $workspace = $this->provisionWorkspace->handle($project->owner, Config::string('builder.verification.workspace_driver'));
            $driver = $this->workspaces->driver($workspace->driver);
            $id = (string) $workspace->driver_id;
            $this->repository->withCheckout($project, $commit, fn (string $source) => $driver->copyDirectory($id, $source));
            $read = fn (string $path) => rescue(fn () => $driver->readFile($id, $path), null, report: false);

            /** @var list<array{name: string, command: list<string>, timeout: int, needs?: string}> $setup */
            $setup = Config::array('builder.verification.setup');

            foreach ($setup as $step) {
                if (! $this->needs->met($workspace, $step)) {
                    continue;
                }

                $command = $this->runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);

                if ($command->exit_code !== 0 || $command->timed_out) {
                    return ['routes' => [], 'map' => null, 'pages' => [], 'error' => (string) __('The app could not be set up to run (:step).', ['step' => $step['name']])];
                }
            }

            $this->runWorkspaceCommand->handle($workspace, ['sh', '-c', 'php artisan route:list --json --except-vendor > '.self::ROUTES_FILE], 120);
            $routes = json_decode((string) $read(self::ROUTES_FILE), true);

            /** @var array{enabled: bool, command: list<string>, timeout: int, report: string, listing: string} $config */
            $config = Config::array('builder.verification.test_map');
            $command = $this->runWorkspaceCommand->handle($workspace, $config['command'], $config['timeout']);
            $map = $command->exit_code === 0 && ! $command->timed_out ? TestMap::parse((string) $read($config['report']), $read($config['listing'])) : null;
            $requests = $map !== null && Config::boolean('builder.verification.traces.enabled') ? AppTraces::parse((string) $read(Config::string('builder.verification.traces.report'))) : [];

            TestObservation::create([
                'project_id' => $project->id,
                'tests' => $map->tests ?? [],
                'files' => $map->files ?? [],
                'lines' => $map->lines ?? [],
                'error' => match (true) {
                    $map === null => __('The tests could not run with code coverage.'),
                    $map->isEmpty() => __('The tests ran, but no code coverage was recorded.'),
                    default => null,
                },
            ]);

            /** @var list<array<string, mixed>> $routes */
            $routes = is_array($routes) ? array_values(array_filter($routes, is_array(...))) : [];

            return [
                'routes' => $routes,
                'map' => $map === null || $map->isEmpty() ? null : $map,
                'pages' => $this->pages($requests),
                'error' => $map === null ? (string) __('The app\'s tests did not all pass, so what they touch is not known.') : null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['routes' => [], 'map' => null, 'pages' => [], 'error' => (string) __('The app could not be run, so only its code was read.')];
        } finally {
            if ($workspace !== null) {
                rescue(fn () => $this->destroyWorkspace->handle($workspace));
            }
        }
    }

    /**
     * Say what the code holds, by the framework's conventions: its tables,
     * models, emails, notices, background work, rules on who may do what,
     * and the packages it is built on.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    protected function code(Project $project, string $commit, array $files): array
    {
        $classes = fn (string $folder) => array_values(array_map(
            fn (string $path) => Str::of($path)->after($folder.'/')->beforeLast('.php')->replace('/', '\\')->value(),
            array_filter($files, fn (string $path) => str_starts_with($path, $folder.'/') && str_ends_with($path, '.php')),
        ));
        $tables = array_values(array_unique(array_filter(array_map(
            fn (string $path) => preg_match('#^database/migrations/[^/]*create_(\w+?)_table\.php$#', $path, $match) === 1 ? $match[1] : null,
            // The drafter's file list leaves migrations out, so read them here.
            $this->repository->files($project, $commit),
        ))));
        $composer = json_decode((string) $this->repository->show($project, $commit, 'composer.json'), true);
        $packages = array_keys(array_filter((array) ($composer['require'] ?? []), fn (mixed $version, string $name) => str_contains($name, '/'), ARRAY_FILTER_USE_BOTH));

        $lines = [];

        foreach ([
            'Tables' => $tables,
            'Models' => $classes('app/Models'),
            'Emails' => $classes('app/Mail'),
            'Notifications' => $classes('app/Notifications'),
            'Background jobs' => $classes('app/Jobs'),
            'Commands' => $classes('app/Console/Commands'),
            'Policies (who may do what)' => $classes('app/Policies'),
            'Form requests (what input is accepted)' => $classes('app/Http/Requests'),
            'Livewire components' => $classes('app/Livewire'),
            'Packages' => $packages,
        ] as $label => $names) {
            if ($names !== []) {
                $lines[] = "{$label}: ".implode(', ', array_slice($names, 0, 80));
            }
        }

        return $lines === [] ? [] : ['# What the code holds', ...$lines, ''];
    }

    /**
     * Say each route with the middleware that guards it.
     *
     * @param  list<array<string, mixed>>  $routes
     * @return list<string>
     */
    protected function routes(array $routes): array
    {
        $lines = array_map(function (array $route) {
            $middleware = array_values(array_filter(
                array_map(strval(...), (array) ($route['middleware'] ?? [])),
                fn (string $name) => ! in_array($name, ['web', 'api'], true) && ! str_starts_with($name, 'Illuminate\\'),
            ));

            return '- '.Str::before((string) ($route['method'] ?? 'GET'), '|').' /'.ltrim((string) ($route['uri'] ?? ''), '/')
                .' → '.class_basename(str_replace('@', '::', (string) ($route['action'] ?? '')))
                .($middleware === [] ? '' : ' ['.implode(', ', array_map(fn (string $name) => class_basename($name), $middleware)).']');
        }, $routes);

        return $lines === [] ? [] : ['# Routes, with who may use them', ...array_slice($lines, 0, 200), ''];
    }

    /**
     * Say what the app's own tests check and which code each test file ran,
     * leaving out the code most tests run, which says nothing about a part.
     *
     * @return list<string>
     */
    protected function tests(?TestMap $map, ?string $error): array
    {
        if ($map === null) {
            return $error === null ? [] : ['# The app\'s tests', $error, ''];
        }

        $foundation = $map->foundation();
        $byFile = [];

        foreach ($map->tests as $index => $test) {
            $byFile[(string) ($test['file'] ?? 'unknown')]['tests'][] = $map->sentence($index);
        }

        foreach ($map->files as $path => $tests) {
            if (in_array($path, $foundation, true)) {
                continue;
            }

            foreach ($tests as $test) {
                $byFile[(string) ($map->tests[$test]['file'] ?? 'unknown')]['ran'][$path] = true;
            }
        }

        $lines = ['# The app\'s tests: what each file checks, and the code it ran'];

        foreach ($byFile as $file => $seen) {
            $lines[] = "- {$file}: ".implode('; ', array_slice(array_unique($seen['tests'] ?? []), 0, 12));

            if (($seen['ran'] ?? []) !== []) {
                $lines[] = '  ran: '.implode(', ', array_slice(array_keys($seen['ran']), 0, 20));
            }
        }

        return [...$lines, ''];
    }

    /**
     * Find, for each page the tests opened, the app's code files it ran.
     *
     * @param  list<array<string, mixed>>  $requests
     * @return list<array{route: string, files: list<string>}>
     */
    protected function pages(array $requests): array
    {
        $pages = [];

        foreach ($requests as $request) {
            $route = (string) ($request['route'] ?? '');

            if ($route === '' || ! in_array($request['method'] ?? '', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                continue;
            }

            $key = "{$request['method']} {$route}";

            foreach ((array) ($request['effects'] ?? []) as $effect) {
                $at = is_array($effect) ? ($effect['at'] ?? null) : null;

                if (is_string($at) && $at !== '') {
                    $pages[$key][Str::beforeLast($at, ':')] = true;
                }
            }

            $pages[$key] ??= [];
        }

        return array_map(fn (string $route, array $files) => ['route' => $route, 'files' => array_keys($files)], array_keys($pages), $pages);
    }

    /**
     * Say the pages the tests opened and the app's code each one ran.
     *
     * @param  list<array{route: string, files: list<string>}>  $pages
     * @return list<string>
     */
    protected function pageLines(array $pages): array
    {
        $lines = array_map(fn (array $page) => "- {$page['route']}".($page['files'] === [] ? '' : ': '.implode(', ', array_slice($page['files'], 0, 10))), $pages);

        return $lines === [] ? [] : ['# Pages the tests opened, and the app code each ran', ...array_slice($lines, 0, 150), ''];
    }
}
