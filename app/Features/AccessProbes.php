<?php

namespace App\Features;

use App\Scaffolding\Scaffold;
use Illuminate\Support\Str;

/**
 * Try who may do what with each record the plan described, through the
 * app's own routes (§26.11). The plan says, for example, that only the
 * person who added a booking may change it. Each probe sends one request
 * as one actor, a signed-out visitor or another signed-in person, to a
 * route that works on a booking, in the app's own test harness. A policy
 * that exists is no proof that a route uses it, so only the request is
 * evidence: the record changed, was removed, was added or was shown.
 *
 * Routes are matched by Laravel's conventions: a route parameter named
 * after the model, and the HTTP method for the action. Routes that need
 * other values, or sit on their own domain, are left out and counted.
 *
 * @phpstan-import-type Record from Scaffold
 *
 * @phpstan-type Scope array{param: string, model: string, key: string}
 * @phpstan-type Probe array{record: string, noun: string, creator: string|null, action: string, actor: string, rule: string, method: string, uri: string, param: string, key: string|null, scope: Scope|null}
 * @phpstan-type Observed array{id: int, status: int, changed: bool, invalid: bool, policy: bool|null}
 * @phpstan-type Finding array{record: string, noun: string, creator: string|null, action: string, actor: string, rule: string, method: string, uri: string, param: string, key: string|null, scope: Scope|null, status: int}
 */
class AccessProbes
{
    public const GUEST = 'guest';

    public const STRANGER = 'stranger';

    /**
     * Plan the probes: for each record with access rules, each route that
     * works on it, and each actor the rules refuse.
     *
     * @param  list<Record>  $records
     * @return array{probes: list<Probe>, unmatched: list<string>}
     */
    public static function plan(array $records, string $routeList, int $limit): array
    {
        $routes = json_decode(trim($routeList), true);
        $probes = [];
        $unmatched = [];

        if (! is_array($routes) || ! array_is_list($routes)) {
            return ['probes' => [], 'unmatched' => []];
        }

        foreach ($records as $record) {
            if (($record['access'] ?? null) === null) {
                continue;
            }

            $found = false;

            foreach (self::routesFor($record['name'], $routes) as $route) {
                $found = true;

                foreach ([self::GUEST, self::STRANGER] as $actor) {
                    $rule = $record['access'][$route['action']];

                    if (! self::refuses($rule, $route['action'], $actor)) {
                        continue;
                    }

                    $probes[] = [
                        'record' => $record['name'],
                        'noun' => $record['label'] ?? self::words($record['name']),
                        'creator' => Scaffold::creator($record['fields']),
                        'action' => $route['action'],
                        'actor' => $actor,
                        'rule' => $rule,
                        ...array_intersect_key($route, array_flip(['method', 'uri', 'param', 'key', 'scope'])),
                    ];
                }
            }

            if (! $found) {
                $unmatched[] = $record['name'];
            }
        }

        return ['probes' => array_slice($probes, 0, $limit), 'unmatched' => $unmatched];
    }

    /**
     * Plan probes for records the app already had, where the plan states no
     * rule: the app's own policy is the rule. Each route the change's
     * controllers serve is tried by both actors, and the test asks the
     * policy first, so only a request the policy refuses can be a finding.
     * Routes of controllers the change did not touch are left alone: their
     * problems are not the change's.
     *
     * @param  list<string>  $models  Models with a policy, by class name
     * @param  list<string>  $controllers  Controllers the change touched, by class name
     * @return list<Probe>
     */
    public static function fromPolicies(array $models, string $routeList, array $controllers, int $limit): array
    {
        $routes = json_decode(trim($routeList), true);
        $probes = [];

        if (! is_array($routes) || ! array_is_list($routes) || $controllers === []) {
            return [];
        }

        foreach ($models as $model) {
            foreach (self::routesFor($model, $routes) as $route) {
                if (! in_array($route['controller'], $controllers, true)) {
                    continue;
                }

                foreach ([self::GUEST, self::STRANGER] as $actor) {
                    $probes[] = [
                        'record' => $model,
                        'noun' => self::words($model),
                        'creator' => null,
                        'action' => $route['action'],
                        'actor' => $actor,
                        'rule' => 'policy',
                        ...array_intersect_key($route, array_flip(['method', 'uri', 'param', 'key', 'scope'])),
                    ];
                }
            }
        }

        return array_slice($probes, 0, $limit);
    }

    /**
     * Plan probes across teams: a signed-in person outside a team tries
     * the team's own routes and the routes of the records it owns. No rule
     * needs stating: crossing a team is always a finding (§26.10), so the
     * policy is not asked. Only the routes of controllers the change
     * touched are tried.
     *
     * @param  array{tenants: list<string>, owned: array<string, array{tenant: string, key: string}>}  $teams  From introspection()
     * @param  list<string>  $controllers  Controllers the change touched, by class name
     * @return list<Probe>
     */
    public static function forTenants(array $teams, string $routeList, array $controllers, int $limit): array
    {
        $routes = json_decode(trim($routeList), true);
        $probes = [];

        if (! is_array($routes) || ! array_is_list($routes) || $controllers === []) {
            return [];
        }

        $records = [
            ...array_map(fn (string $tenant) => [$tenant, null], $teams['tenants']),
            ...array_map(fn (string $model, array $owned) => [$model, ['model' => $owned['tenant'], 'key' => $owned['key']]], array_keys($teams['owned']), $teams['owned']),
        ];

        foreach ($records as [$model, $tenant]) {
            foreach (self::routesFor($model, $routes, $tenant) as $route) {
                if ($route['action'] === 'create' || ! in_array($route['controller'], $controllers, true)) {
                    continue;
                }

                $probes[] = [
                    'record' => $model,
                    'noun' => self::words($model),
                    'creator' => null,
                    'action' => $route['action'],
                    'actor' => self::STRANGER,
                    'rule' => 'tenant:'.($tenant['model'] ?? $model),
                    ...array_intersect_key($route, array_flip(['method', 'uri', 'param', 'key', 'scope'])),
                ];
            }
        }

        return array_slice($probes, 0, $limit);
    }

    /**
     * The script that lists the app's teams, run with the app's own PHP:
     * models with members (a many-to-many link to the user model) and, for
     * each other model, the team it belongs to and by which column. It
     * reads only what the models declare, through their return types.
     */
    public static function introspection(): string
    {
        return <<<'PHP'
<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = config('auth.providers.users.model');
$models = [];

foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
    $class = 'App\\Models\\'.basename($file, '.php');

    if (class_exists($class) && is_subclass_of($class, Illuminate\Database\Eloquent\Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
        $models[] = $class;
    }
}

$relations = function (string $class, string $type) {
    $found = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $returns = $method->getReturnType();

        if ($method->class === $class && $method->getNumberOfParameters() === 0 && $returns instanceof ReflectionNamedType && is_a($returns->getName(), $type, true)) {
            try {
                $found[] = (new $class)->{$method->name}();
            } catch (Throwable) {
            }
        }
    }

    return $found;
};

$tenants = [];

foreach ($models as $class) {
    foreach ($relations($class, Illuminate\Database\Eloquent\Relations\BelongsToMany::class) as $relation) {
        if ($relation->getRelated() instanceof $user && $class !== $user) {
            $tenants[] = class_basename($class);
        }
    }
}

$owned = [];

foreach ($models as $class) {
    foreach ($relations($class, Illuminate\Database\Eloquent\Relations\BelongsTo::class) as $relation) {
        if ($class !== $user && in_array(class_basename($relation->getRelated()), $tenants, true) && ! isset($owned[class_basename($class)])) {
            $owned[class_basename($class)] = ['tenant' => class_basename($relation->getRelated()), 'key' => $relation->getForeignKeyName()];
        }
    }
}

echo json_encode(['tenants' => array_values(array_unique($tenants)), 'owned' => $owned]), "\n";

PHP;
    }

    /**
     * Read what introspection() printed, or null when it printed nothing
     * usable.
     *
     * @return array{tenants: list<string>, owned: array<string, array{tenant: string, key: string}>}|null
     */
    public static function teams(string $output): ?array
    {
        $data = json_decode(trim((string) Str::of($output)->trim()->explode("\n")->last()), true);

        if (! is_array($data) || ! is_array($data['tenants'] ?? null) || ! is_array($data['owned'] ?? null)) {
            return null;
        }

        $word = fn (mixed $value) => is_string($value) && preg_match('/^\w+$/', $value) === 1;
        $owned = [];

        foreach ($data['owned'] as $model => $link) {
            if ($word($model) && is_array($link) && $word($link['tenant'] ?? null) && $word($link['key'] ?? null)) {
                $owned[(string) $model] = ['tenant' => $link['tenant'], 'key' => $link['key']];
            }
        }

        return ['tenants' => array_values(array_filter($data['tenants'], $word)), 'owned' => $owned];
    }

    /**
     * Say a model's name in words.
     */
    protected static function words(string $model): string
    {
        return Str::of($model)->snake(' ')->lower()->toString();
    }

    /**
     * Determine if a rule refuses an actor. Adding a record has no person
     * who added it yet, so "creator" there means anyone signed in.
     */
    protected static function refuses(string $rule, string $action, string $actor): bool
    {
        return match ($actor) {
            self::GUEST => $rule !== 'everyone',
            default => $rule === 'creator' && $action !== 'create',
        };
    }

    /**
     * Find the routes that work on a model, by Laravel's conventions: a
     * parameter named after it, or adding one at the same controller.
     * A route under the record's team, such as teams/{team}/rooms/{room},
     * is found too when the team is given.
     *
     * @param  list<mixed>  $routes
     * @param  array{model: string, key: string}|null  $tenant
     * @return list<array{action: string, method: string, uri: string, param: string, key: string|null, controller: string|null, scope: Scope|null}>
     */
    protected static function routesFor(string $model, array $routes, ?array $tenant = null): array
    {
        $name = Str::camel($model);
        $controllers = [];
        $found = [];
        $adding = [];

        foreach ($routes as $route) {
            if (! is_array($route) || ! is_string($route['uri'] ?? null) || ! is_string($route['method'] ?? null) || is_string($route['domain'] ?? null)) {
                continue;
            }

            $uri = '/'.ltrim($route['uri'], '/');
            $controller = is_string($route['action'] ?? null) ? Str::before($route['action'], '@') : null;
            preg_match_all('/\{(\w+)(?::(\w+))?\??\}/', $uri, $params, PREG_SET_ORDER);
            $methods = array_values(array_diff(explode('|', $route['method']), ['HEAD']));

            if ($params === []) {
                if (in_array('POST', $methods, true)) {
                    $adding[] = ['uri' => $uri, 'controller' => $controller, 'name' => $route['name'] ?? null];
                }

                continue;
            }

            $scope = null;

            if ($tenant !== null && count($params) === 2 && Str::camel($params[0][1]) === Str::camel($tenant['model'])) {
                $scope = ['param' => $params[0][1], 'model' => $tenant['model'], 'key' => $tenant['key']];
                $params = [$params[1]];
            }

            // A route that needs another value is left out.
            if (count($params) !== 1 || Str::camel($params[0][1]) !== $name) {
                continue;
            }

            $controllers[] = $controller;

            foreach ($methods as $method) {
                $action = match ($method) {
                    'GET' => is_string($route['action'] ?? null) && str_ends_with($route['action'], '@edit') ? 'update' : 'view',
                    'PUT', 'PATCH' => 'update',
                    'DELETE' => 'delete',
                    default => null,
                };

                if ($action !== null) {
                    $found[] = ['action' => $action, 'method' => $method, 'uri' => $uri, 'param' => $params[0][1], 'key' => ($params[0][2] ?? '') === '' ? null : $params[0][2], 'controller' => $controller, 'scope' => $scope];
                }
            }
        }

        foreach ($adding as $route) {
            if (($route['controller'] !== null && in_array($route['controller'], $controllers, true)) || $route['name'] === Str::snake(Str::pluralStudly($model)).'.store') {
                $found[] = ['action' => 'create', 'method' => 'POST', 'uri' => $route['uri'], 'param' => '', 'key' => null, 'controller' => $route['controller'], 'scope' => null];
            }
        }

        return $found;
    }

    /**
     * Write the test that sends each probe and notes what it did. It never
     * fails on what it finds; the report is read back instead.
     *
     * @param  list<Probe>  $probes
     */
    public static function test(array $probes, string $report): string
    {
        $methods = [];

        foreach ($probes as $id => $probe) {
            $methods[] = sprintf(
                "    public function test_probe_%d(): void\n    {\n        \$this->probe(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s);\n    }",
                $id,
                $id,
                var_export('App\\Models\\'.$probe['record'], true),
                var_export($probe['creator'], true),
                var_export($probe['action'], true),
                var_export($probe['method'], true),
                var_export($probe['uri'], true),
                var_export($probe['param'], true),
                var_export($probe['key'], true),
                var_export($probe['actor'], true),
                var_export($probe['rule'] === 'policy', true),
                $probe['scope'] === null ? 'null' : sprintf("['param' => %s, 'model' => %s, 'key' => %s]", ...array_values(array_map(fn (string $value) => var_export($value, true), $probe['scope']))),
            );
        }

        $methods = implode("\n\n", $methods);
        $report = var_export($report, true);

        return <<<PHP
<?php

namespace Tests\Feature;

use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AccessProbeTest extends TestCase
{
    use RefreshDatabase;

{$methods}

    /**
     * Send one request as one actor and note what it did to the record,
     * and, when asked, what the app's own policy says about it first.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  \$model
     * @param  array{param: string, model: string, key: string}|null  \$scope  The team in the address, when there is one
     */
    private function probe(int \$id, string \$model, ?string \$creator, string \$action, string \$method, string \$uri, string \$param, ?string \$key, string \$actor, bool \$askPolicy, ?array \$scope): void
    {
        \$owner = User::factory()->create();
        \$record = \$model::factory()->create(\$creator === null ? [] : [\$creator => \$owner->getKey()]);
        \$before = \$record->fresh()?->getAttributes() ?? [];
        \$count = \$model::query()->count();
        \$url = \$param === '' ? \$uri : (string) preg_replace('/\{'.\$param.'(:\w+)?\??\}/', (string) (\$key === null ? \$record->getRouteKey() : \$record->getAttribute(\$key)), \$uri);

        if (\$scope !== null) {
            \$team = ('App\\\\Models\\\\'.\$scope['model'])::query()->findOrFail(\$record->getAttribute(\$scope['key']));
            \$url = (string) preg_replace('/\{'.\$scope['param'].'(:\w+)?\??\}/', (string) \$team->getRouteKey(), \$url);
        }

        \$payload = in_array(\$action, ['create', 'update'], true)
            ? array_map(fn (mixed \$value) => match (true) {
                \$value instanceof DateTimeInterface => \$value->format('Y-m-d H:i:s'),
                \$value instanceof BackedEnum => \$value->value,
                default => \$value,
            }, array_diff_key(\$model::factory()->raw(), array_flip(array_filter([\$creator]))))
            : [];

        \$user = \$actor === 'stranger' ? User::factory()->create() : null;
        \$policy = \$askPolicy ? Gate::forUser(\$user)->allows(\$action, \$action === 'create' ? \$model : \$record) : null;

        if (\$user !== null) {
            \$this->actingAs(\$user);
        }

        \$response = \$this->call(\$method, \$url, \$payload);
        \$after = \$model::query()->whereKey(\$record->getKey())->first()?->getAttributes();

        \$changed = match (\$action) {
            'create' => \$model::query()->count() > \$count,
            'update' => \$after !== null && array_diff_key(\$after, ['updated_at' => true]) != array_diff_key(\$before, ['updated_at' => true]),
            'delete' => \$after === null,
            default => false,
        };

        file_put_contents(base_path({$report}), json_encode([
            'id' => \$id,
            'status' => \$response->getStatusCode(),
            'changed' => \$changed,
            'invalid' => \$response->getStatusCode() === 422 || session()->has('errors'),
            'policy' => \$policy,
        ]).PHP_EOL, FILE_APPEND);

        \$this->addToAssertionCount(1);
    }
}

PHP;
    }

    /**
     * Read the report into what each probe did, by probe.
     *
     * @return array<int, Observed>
     */
    public static function parse(string $report): array
    {
        $observed = [];

        foreach (preg_split('/\R/', trim($report)) ?: [] as $line) {
            $data = json_decode($line, true);

            if (is_array($data) && is_int($data['id'] ?? null) && is_int($data['status'] ?? null)) {
                $observed[$data['id']] = ['id' => $data['id'], 'status' => $data['status'], 'changed' => ($data['changed'] ?? false) === true, 'invalid' => ($data['invalid'] ?? false) === true, 'policy' => is_bool($data['policy'] ?? null) ? $data['policy'] : null];
            }
        }

        return $observed;
    }

    /**
     * Judge each probe: a refused actor that changed, removed, added or saw
     * the record is a finding. A request that broke, or whose sent values
     * were turned down before anything was decided, proves nothing.
     *
     * @param  list<Probe>  $probes
     * @param  array<int, Observed>  $observed
     * @return array{tried: int, refused: int, findings: list<Finding>, untried: int}
     */
    public static function measure(array $probes, array $observed): array
    {
        $findings = [];
        $refused = 0;
        $untried = 0;

        foreach ($probes as $id => $probe) {
            $seen = $observed[$id] ?? null;

            // The app's own policy lets this actor do it: nothing to judge.
            if ($probe['rule'] === 'policy' && $seen !== null && $seen['policy'] !== false) {
                if ($seen['policy'] === null) {
                    $untried++;
                }

                continue;
            }

            if ($seen === null || $seen['status'] >= 500 || ($seen['invalid'] && ! $seen['changed'])) {
                $untried++;

                continue;
            }

            $allowed = $probe['method'] === 'GET' ? $seen['status'] === 200 : $seen['changed'];

            if ($allowed) {
                $findings[] = [...$probe, 'status' => $seen['status']];
            } else {
                $refused++;
            }
        }

        return ['tried' => $refused + count($findings), 'refused' => $refused, 'findings' => $findings, 'untried' => $untried];
    }

    /**
     * Say what the probes found, for the agent that repairs the change
     * and for the owner reading the check.
     *
     * @param  array{tried: int, refused: int, findings: list<Finding>, untried: int}  $measured
     * @param  list<string>  $unmatched
     */
    public static function describe(array $measured, array $unmatched): string
    {
        $lines = [];

        foreach ($measured['findings'] as $finding) {
            $team = str_starts_with($finding['rule'], 'tenant:') ? self::words(Str::after($finding['rule'], 'tenant:')) : null;
            $who = match (true) {
                $finding['actor'] === self::GUEST => 'Someone who is not signed in',
                $team !== null => "A signed-in person outside the {$team}",
                default => 'A signed-in person who did not add it',
            };
            $did = match ($finding['action']) {
                'view' => "could see a {$finding['noun']}",
                'create' => "could add a {$finding['noun']}",
                'update' => $finding['method'] === 'GET' ? "could open the form to change a {$finding['noun']}" : "could change a {$finding['noun']}",
                default => "could remove a {$finding['noun']}",
            };
            $rule = match (true) {
                $team !== null => "Nobody outside a {$team} may reach it or its records",
                $finding['rule'] === 'policy' => "The app's own {$finding['record']} policy refuses this",
                $finding['rule'] === 'creator' => 'The plan allows only the person who added it',
                default => 'The plan allows only people who are signed in',
            };

            $fix = $team === null ? "Make this route check the {$finding['record']} policy." : "Make this route check that the person belongs to the {$team}.";

            $lines[] = "{$who} {$did}: {$finding['method']} {$finding['uri']} answered {$finding['status']}. {$rule}. {$fix}";
        }

        $lines[] = "Tried {$measured['tried']} requests as a signed-out visitor and as another signed-in person; {$measured['refused']} ".($measured['refused'] === 1 ? 'was' : 'were').' refused, as they should be.';

        if ($measured['untried'] > 0) {
            $lines[] = "{$measured['untried']} could not be judged: the request broke, or the values sent were turned down first.";
        }

        if ($unmatched !== []) {
            $lines[] = 'No route was found for: '.implode(', ', $unmatched).'.';
        }

        return implode("\n", $lines);
    }
}
