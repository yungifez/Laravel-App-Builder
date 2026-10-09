<?php

namespace App\Features;

use Illuminate\Support\Str;

/**
 * Put another person's record in an address (§26.11). Each route the change
 * touched that binds models is sent twice by the same signed-in person:
 * first with records of their own, then with records of someone else. The
 * first send must work; it shows the route can be used at all. When the
 * second works too, the person reached what is not theirs.
 *
 * Whose a record is comes from the models alone: the belongsTo links from
 * it to a user, or to a team with members. The app's own factories make
 * each person's records, and the person is the user at the end of a link
 * and a member of each team at the end of one. A model with no such link
 * is shared, and its routes are left out and counted.
 *
 * An address with more than one record is also sent with the person's own
 * records and someone else's last one: my project, your task.
 *
 * A form that saves a record is also sent with someone else's record in
 * a key that links it to its owner (a task's project_id), at an address
 * of the person's own. It is a finding only when a saved row then points
 * at their record: rows linked to it are counted before and after.
 *
 * A form that saves a record, the person's own account among them, is also
 * sent with extra fields the record's table has and the form does not ask
 * for, such as role or is_admin. It is a finding only when a saved row then
 * holds the value sent, counted before and after, and the same send without
 * the extra fields did not save it too. A field the route's code names is
 * one the form asks for, such as an admin's own "change role" form.
 *
 * A route that removes a record is also sent for a record of the person's
 * own that other records hang off: one of each of its children, made by
 * their factories with the key that links them. A bare record of theirs is
 * removed first, to show the route works. When that worked and the one
 * with children broke the page, the live app breaks on the first record
 * that has any. A record kept with soft deletes never meets the database's
 * links, and links the database does not enforce prove nothing, so
 * neither is tried.
 *
 * @phpstan-type Param array{name: string, model: string|null, field: string|null}
 * @phpstan-type Step array{relation: string, model: string, key: string|null}
 * @phpstan-type Owner array{path: list<Step>, end: string}
 * @phpstan-type Tenant array{relation: string, column: string|null, role: string|null}
 * @phpstan-type Found array{user: string, routes: list<array{methods: list<string>, uri: string, name: string|null, domain: string|null, controller: string|null, action: string|null, params: list<Param>, named: list<string>}>, owners: array<string, list<Owner>>, tenants: array<string, Tenant>, children: array<string, list<Step>>}
 * @phpstan-type Probe array{method: string, uri: string, action: string, params: list<array{name: string, model: string, field: string|null}>, leaf: string, payload: string|null, mode: string, ability: string|null, team: string|null, key: string|null, target: string|null, named: list<string>}
 * @phpstan-type Sent array{status: int, invalid: bool, writes: int, landed: int|null, raised: list<string>}
 * @phpstan-type Observed array{id: int, owners: bool, broke: bool, none: bool, control: Sent|null, swap: Sent|null, guest: int|null, policy: bool|null, children: list<string>, exception: string|null}
 * @phpstan-type Finding array{method: string, uri: string, action: string, params: list<array{name: string, model: string, field: string|null}>, leaf: string, payload: string|null, mode: string, ability: string|null, team: string|null, key: string|null, target: string|null, named: list<string>, status: int, raised: list<string>, children: list<string>, exception: string|null}
 * @phpstan-type Measured array{tried: int, refused: int, shared: int, findings: list<Finding>, untried: int}
 */
class SwapProbes
{
    /**
     * Every record in the address is someone else's.
     */
    public const ALL = 'all';

    /**
     * Only the last record in the address is someone else's.
     */
    public const LEAF = 'leaf';

    /**
     * Someone else's record is in a key of the form, not in the address.
     */
    public const FIELD = 'field';

    /**
     * The person's own record, with extra fields the form does not ask for.
     */
    public const RAISE = 'raise';

    /**
     * The person's own record, removed while other records hang off it.
     */
    public const CHILDREN = 'children';

    /**
     * The fields that give a person more than the form offers: rights, a
     * confirmed email, or money. Only those the table has are sent.
     */
    public const RAISED = ['role', 'is_admin', 'admin', 'is_super_admin', 'super_admin', 'is_staff', 'email_verified_at', 'balance', 'credits'];

    /**
     * How many links a record may be from its owner.
     */
    protected const DEPTH = 3;

    /**
     * The script that lists, with the app's own PHP, each route that binds
     * a model, whose each model is (its links to a user or a team), and how
     * a person joins a team. It reads only what the routes and models
     * declare, and asks the database nothing.
     */
    public static function introspection(): string
    {
        $depth = self::DEPTH;
        $raised = self::export(self::RAISED);

        return <<<PHP
<?php

require getcwd().'/vendor/autoload.php';
\$app = require getcwd().'/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\$depth = {$depth};
\$raised = {$raised};

PHP.<<<'PHP'
$user = config('auth.providers.users.model');
$models = [];

foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
    $class = 'App\\Models\\'.basename($file, '.php');

    if (class_exists($class) && is_subclass_of($class, Illuminate\Database\Eloquent\Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
        $models[class_basename($class)] = $class;
    }
}

$relations = function (string $class, string $type) {
    $found = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $returns = $method->getReturnType();

        if ($method->class === $class && $method->getNumberOfParameters() === 0 && $returns instanceof ReflectionNamedType && is_a($returns->getName(), $type, true)) {
            try {
                $found[$method->name] = (new $class)->{$method->name}();
            } catch (Throwable) {
            }
        }
    }

    return $found;
};

// A team is a model with members. A role the pivot casts to an enum is
// given as its first case, which by the usual order has the most rights.
$tenants = [];

foreach ($models as $name => $class) {
    if ($class === $user) {
        continue;
    }

    foreach ($relations($class, Illuminate\Database\Eloquent\Relations\BelongsToMany::class) as $method => $relation) {
        if (! $relation->getRelated() instanceof $user || isset($tenants[$name])) {
            continue;
        }

        $tenants[$name] = ['relation' => $method, 'column' => null, 'role' => null];
        $casts = (new ($relation->getPivotClass()))->getCasts();

        foreach ($relation->getPivotColumns() as $column) {
            $cast = $casts[$column] ?? null;

            if (is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class) && $cast::cases() !== []) {
                $tenants[$name] = ['relation' => $method, 'column' => $column, 'role' => (string) $cast::cases()[0]->value];

                break;
            }
        }
    }
}

// Whose each model is: every chain of belongsTo links from it that ends at
// the user model or at a team.
$owners = [];

foreach ($models as $name => $class) {
    if ($class === $user || isset($tenants[$name])) {
        $owners[$name] = [['path' => [], 'end' => $class === $user ? 'user' : $name]];

        continue;
    }

    $queue = [[$class, []]];

    while ($queue !== [] && count($owners[$name] ?? []) < 4) {
        [$at, $path] = array_shift($queue);

        foreach ($relations($at, Illuminate\Database\Eloquent\Relations\BelongsTo::class) as $method => $relation) {
            $related = get_class($relation->getRelated());
            $next = [...$path, ['relation' => $method, 'model' => class_basename($related), 'key' => $relation->getForeignKeyName()]];

            if ($related === $user || isset($tenants[class_basename($related)])) {
                $owners[$name][] = ['path' => $next, 'end' => $related === $user ? 'user' : class_basename($related)];
            } elseif (count($next) < $depth && ! in_array(class_basename($related), array_column($next, 'model'), true) && $related !== $class) {
                $queue[] = [$related, $next];
            }
        }
    }
}

$routes = [];

foreach (app('router')->getRoutes() as $route) {
    $bound = [];

    try {
        foreach ($route->signatureParameters(['subClass' => Illuminate\Database\Eloquent\Model::class]) as $parameter) {
            $type = Illuminate\Support\Reflector::getParameterClassName($parameter);

            if ($type !== null && in_array($type, $models, true)) {
                $bound[$parameter->getName()] = class_basename($type);
            }
        }
    } catch (Throwable) {
    }

    // A form with no record in its address may still name one in a key,
    // or save the person's own account.
    if ($bound === [] && ($route->parameterNames() !== [] || array_intersect(['POST', 'PUT', 'PATCH'], $route->methods()) === [])) {
        continue;
    }

    // The extra fields the route's code names, in its action or its form
    // request: those the form asks for.
    $source = '';

    try {
        if ($route->getControllerClass() !== null) {
            $action = new ReflectionMethod($route->getControllerClass(), $route->getActionMethod() === $route->getControllerClass() ? '__invoke' : $route->getActionMethod());
            $source = implode('', array_slice(file((string) $action->getFileName()) ?: [], $action->getStartLine() - 1, $action->getEndLine() - $action->getStartLine() + 1));

            foreach ($action->getParameters() as $parameter) {
                $type = $parameter->getType();

                // The form request, and the traits and parents it keeps its
                // rules in, as the starter kits' profile rules are.
                if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Illuminate\Foundation\Http\FormRequest::class)) {
                    foreach ([$type->getName(), ...array_values(class_parents($type->getName()) ?: []), ...array_values(class_uses_recursive($type->getName()))] as $class) {
                        $file = (string) (new ReflectionClass($class))->getFileName();
                        $source .= str_starts_with($file, app_path()) ? (string) file_get_contents($file) : '';
                    }
                }
            }
        }
    } catch (Throwable) {
    }

    $named = array_values(array_filter($raised, fn (string $field) => preg_match('/[\'"]'.$field.'[\'".]/', $source) === 1));

    $params = [];

    foreach ($route->parameterNames() as $param) {
        $model = $bound[$param] ?? $bound[Illuminate\Support\Str::camel($param)] ?? null;
        $params[] = ['name' => $param, 'model' => $model, 'field' => $route->bindingFieldFor($param)];
    }

    $routes[] = [
        'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
        'uri' => '/'.ltrim($route->uri(), '/'),
        'name' => $route->getName(),
        'domain' => $route->getDomain(),
        'controller' => $route->getControllerClass(),
        'action' => $route->getActionMethod(),
        'params' => $params,
        'named' => $named,
    ];
}

// What hangs off each model: its hasMany and hasOne children, by the key
// that links them. A morph link names no table of its own, so it is left out.
$children = [];

foreach ($models as $name => $class) {
    foreach ([...$relations($class, Illuminate\Database\Eloquent\Relations\HasMany::class), ...$relations($class, Illuminate\Database\Eloquent\Relations\HasOne::class)] as $method => $relation) {
        $related = get_class($relation->getRelated());

        if (in_array($related, $models, true) && count($children[$name] ?? []) < 4) {
            $children[$name][] = ['relation' => $method, 'model' => class_basename($related), 'key' => $relation->getForeignKeyName()];
        }
    }
}

echo json_encode(['user' => class_basename($user), 'routes' => $routes, 'owners' => $owners, 'tenants' => $tenants, 'children' => $children]), "\n";

PHP;
    }

    /**
     * Read what introspection() printed, or null when it printed nothing
     * usable. Only names are kept, so nothing the app printed can reach the
     * test as code.
     *
     * @return Found|null
     */
    public static function found(string $output): ?array
    {
        $data = json_decode(trim((string) Str::of($output)->trim()->explode("\n")->last()), true);
        $word = fn (mixed $value) => is_string($value) && preg_match('/^\w+$/', $value) === 1;

        if (! is_array($data) || ! $word($data['user'] ?? null) || ! is_array($data['routes'] ?? null) || ! is_array($data['owners'] ?? null) || ! is_array($data['tenants'] ?? null)) {
            return null;
        }

        $tenants = [];

        foreach ($data['tenants'] as $name => $tenant) {
            if ($word($name) && is_array($tenant) && $word($tenant['relation'] ?? null) && (($tenant['column'] ?? null) === null || $word($tenant['column'])) && (($tenant['role'] ?? null) === null || $word($tenant['role']))) {
                $tenants[(string) $name] = ['relation' => $tenant['relation'], 'column' => $tenant['column'] ?? null, 'role' => $tenant['role'] ?? null];
            }
        }

        $owners = [];

        foreach ($data['owners'] as $name => $chains) {
            foreach (is_array($chains) && $word($name) ? $chains : [] as $chain) {
                $path = is_array($chain) && is_array($chain['path'] ?? null) ? $chain['path'] : null;
                $end = is_array($chain) ? ($chain['end'] ?? null) : null;

                if ($path === null || ! ($end === 'user' || isset($tenants[$end]))) {
                    continue;
                }

                $steps = array_values(array_filter($path, fn (mixed $step) => is_array($step) && $word($step['relation'] ?? null) && $word($step['model'] ?? null)));

                if (count($steps) === count($path)) {
                    $owners[(string) $name][] = ['path' => array_map(fn (array $step) => ['relation' => $step['relation'], 'model' => $step['model'], 'key' => $word($step['key'] ?? null) ? $step['key'] : null], $steps), 'end' => $end];
                }
            }
        }

        $routes = [];

        foreach ($data['routes'] as $route) {
            if (! is_array($route) || ! is_string($route['uri'] ?? null) || ! is_array($route['methods'] ?? null) || ! is_array($route['params'] ?? null)) {
                continue;
            }

            $params = [];

            foreach ($route['params'] as $param) {
                if (! is_array($param) || ! $word($param['name'] ?? null)) {
                    continue 2;
                }

                $params[] = ['name' => $param['name'], 'model' => $word($param['model'] ?? null) ? $param['model'] : null, 'field' => $word($param['field'] ?? null) ? $param['field'] : null];
            }

            $routes[] = [
                'methods' => array_values(array_filter($route['methods'], is_string(...))),
                'uri' => $route['uri'],
                'name' => is_string($route['name'] ?? null) ? $route['name'] : null,
                'domain' => is_string($route['domain'] ?? null) ? $route['domain'] : null,
                'controller' => is_string($route['controller'] ?? null) ? $route['controller'] : null,
                'action' => is_string($route['action'] ?? null) ? $route['action'] : null,
                'params' => $params,
                'named' => array_values(array_intersect(self::RAISED, is_array($route['named'] ?? null) ? $route['named'] : [])),
            ];
        }

        $children = [];

        foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $name => $links) {
            foreach (is_array($links) && $word($name) ? $links : [] as $link) {
                if (is_array($link) && $word($link['relation'] ?? null) && $word($link['model'] ?? null) && $word($link['key'] ?? null)) {
                    $children[(string) $name][] = ['relation' => $link['relation'], 'model' => $link['model'], 'key' => $link['key']];
                }
            }
        }

        return ['user' => $data['user'], 'routes' => $routes, 'owners' => $owners, 'tenants' => $tenants, 'children' => $children];
    }

    /**
     * Plan the swaps: for each route of a controller the change touched
     * whose records all have an owner, each method, and each way to swap.
     * A route a person outside the record's team already tries (`$tried`,
     * as "METHOD uri") is not tried again with one record. A route with a
     * value that is not a record, or a record with no owner, is counted.
     * A route that works on a team's members is the role probes'.
     *
     * @param  Found  $found
     * @param  list<string>  $controllers  Controllers the change touched, by class name
     * @param  list<string>  $tried
     * @return array{probes: list<Probe>, skipped: int}
     */
    public static function plan(array $found, array $controllers, array $tried, int $limit): array
    {
        $probes = [];
        $fields = [];
        $raises = [];
        $removals = [];
        $skipped = 0;

        foreach ($found['routes'] as $route) {
            if ($route['domain'] !== null || $route['controller'] === null || ! in_array($route['controller'], $controllers, true)) {
                continue;
            }

            $fields = [...$fields, ...self::fields($route, $found)];
            $raises = [...$raises, ...self::raises($route, $found)];

            if ($route['params'] === []) {
                continue;
            }

            $params = $route['params'];
            $leaf = $params[count($params) - 1]['model'];

            if ($leaf === $found['user'] && count($params) > 1) {
                continue;
            }

            $owners = $leaf === null ? [] : ($found['owners'][$leaf] ?? []);
            $reached = array_merge(...array_map(fn (array $owner) => [...array_column($owner['path'], 'model'), $owner['end']], $owners ?: [['path' => [], 'end' => '']]));

            // Each record before the last is found on the last one's links.
            $usable = $owners !== [] && array_filter(
                array_slice($params, 0, -1),
                fn (array $param) => $param['model'] === null || ! in_array($param['model'], $reached, true),
            ) === [];

            if (! $usable) {
                $skipped++;

                continue;
            }

            /** @var list<array{name: string, model: string, field: string|null}> $params */
            $team = collect($owners)->first(fn (array $owner) => $owner['end'] !== 'user')['end'] ?? null;

            foreach (array_intersect($route['methods'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
                [$action, $ability, $payload] = match ($method) {
                    'GET' => $route['action'] === 'edit' ? ['update', 'update', null] : ['view', 'view', null],
                    'PUT', 'PATCH' => ['update', 'update', $leaf],
                    'DELETE' => ['delete', 'delete', null],
                    default => self::posting($route['uri'], array_keys($found['owners'])),
                };

                foreach (count($params) > 1 ? [self::ALL, self::LEAF] : [self::ALL] as $mode) {
                    if ($mode === self::ALL && count($params) === 1 && in_array("{$method} {$route['uri']}", $tried, true)) {
                        continue;
                    }

                    $probes[] = ['method' => $method, 'uri' => $route['uri'], 'action' => $action, 'params' => $params, 'leaf' => $leaf, 'payload' => $payload, 'mode' => $mode, 'ability' => $ability, 'team' => $team, 'key' => null, 'target' => null, 'named' => []];
                }

                if ($method === 'DELETE' && ($found['children'][$leaf] ?? []) !== []) {
                    $removals[] = ['method' => $method, 'uri' => $route['uri'], 'action' => 'delete', 'params' => $params, 'leaf' => $leaf, 'payload' => null, 'mode' => self::CHILDREN, 'ability' => null, 'team' => $team, 'key' => null, 'target' => null, 'named' => []];
                }
            }
        }

        // After the addresses, so the limit keeps those first.
        return ['probes' => array_slice([...$probes, ...$fields, ...$raises, ...$removals], 0, $limit), 'skipped' => $skipped];
    }

    /**
     * Plan the form swaps of one route: for each method that saves a
     * record, each key that links that record to its owner. The address
     * holds the person's own records, so each of its records must be found
     * on the saved one's links.
     *
     * @param  array{methods: list<string>, uri: string, name: string|null, domain: string|null, controller: string|null, action: string|null, params: list<Param>}  $route
     * @param  Found  $found
     * @return list<Probe>
     */
    protected static function fields(array $route, array $found): array
    {
        $probes = [];
        $leaf = $route['params'] === [] ? null : $route['params'][count($route['params']) - 1]['model'];

        foreach (array_intersect($route['methods'], ['POST', 'PUT', 'PATCH']) as $method) {
            $saved = $method === 'POST' ? self::posting($route['uri'], array_keys($found['owners']))[2] : $leaf;
            $owners = $saved === null ? [] : ($found['owners'][$saved] ?? []);
            $reached = [$saved, ...array_merge([], ...array_map(fn (array $owner) => [...array_column($owner['path'], 'model'), $owner['end'] === 'user' ? $found['user'] : $owner['end']], $owners))];

            if ($saved === null || $saved === $found['user'] || array_filter($route['params'], fn (array $param) => ! in_array($param['model'], $reached, true)) !== []) {
                continue;
            }

            /** @var list<array{name: string, model: string, field: string|null}> $params */
            $params = $route['params'];
            $keys = [];

            foreach ($owners as $owner) {
                $step = $owner['path'][0] ?? null;

                if ($step === null || $step['key'] === null || isset($keys[$step['key']])) {
                    continue;
                }

                // A key to the user model saves the record in a person's
                // name; any other moves it to a record whose owner is the
                // person or team at the end of the key's links.
                $keys[$step['key']] = true;
                $action = $step['model'] === $found['user'] ? 'assign' : ($method === 'POST' ? 'create' : 'update');
                $probes[] = ['method' => $method, 'uri' => $route['uri'], 'action' => $action, 'params' => $params, 'leaf' => $saved, 'payload' => $saved, 'mode' => self::FIELD, 'ability' => null, 'team' => $owner['end'] === 'user' ? null : $owner['end'], 'key' => $step['key'], 'target' => $step['model'], 'named' => []];
            }
        }

        return $probes;
    }

    /**
     * Plan the extra fields of one route: for each method that saves a
     * record of the person's own, one send with every extra field the form
     * does not ask for. A form with no record in its address that changes
     * something saves the person's own account, as a profile form does.
     *
     * @param  array{methods: list<string>, uri: string, name: string|null, domain: string|null, controller: string|null, action: string|null, params: list<Param>, named: list<string>}  $route
     * @param  Found  $found
     * @return list<Probe>
     */
    protected static function raises(array $route, array $found): array
    {
        $probes = [];
        $leaf = $route['params'] === [] ? $found['user'] : $route['params'][count($route['params']) - 1]['model'];

        foreach (array_intersect($route['methods'], ['POST', 'PUT', 'PATCH']) as $method) {
            $saved = $method === 'POST' ? self::posting($route['uri'], array_keys($found['owners']))[2] : $leaf;
            $owners = $saved === null ? [] : ($found['owners'][$saved] ?? []);
            $reached = [$saved, ...array_merge([], ...array_map(fn (array $owner) => [...array_column($owner['path'], 'model'), $owner['end'] === 'user' ? $found['user'] : $owner['end']], $owners))];

            if ($saved === null || $owners === [] || array_filter($route['params'], fn (array $param) => ! in_array($param['model'], $reached, true)) !== []) {
                continue;
            }

            /** @var list<array{name: string, model: string, field: string|null}> $params */
            $params = $route['params'];
            $probes[] = ['method' => $method, 'uri' => $route['uri'], 'action' => $method === 'POST' ? 'create' : 'update', 'params' => $params, 'leaf' => $saved, 'payload' => $saved, 'mode' => self::RAISE, 'ability' => null, 'team' => null, 'key' => null, 'target' => null, 'named' => $route['named']];
        }

        return $probes;
    }

    /**
     * What a POST to a record's address does: adds the record named after
     * it (projects/{project}/tasks adds a task), or does something to the
     * record itself (orders/{order}/refund). No policy ability is known
     * for either, so the policy is not asked.
     *
     * @param  list<string>  $models
     * @return array{string, null, string|null}
     */
    protected static function posting(string $uri, array $models): array
    {
        $last = Str::afterLast(rtrim($uri, '/'), '/');
        $model = Str::studly(Str::singular($last));

        return ! str_starts_with($last, '{') && in_array($model, $models, true) ? ['create', null, $model] : ['act', null, null];
    }

    /**
     * Write the test that sends each swap and notes what each send did. It
     * never fails on what it finds; the report is read back instead.
     *
     * @param  list<Probe>  $probes
     * @param  Found  $found
     */
    public static function test(array $probes, array $found, string $report): string
    {
        $methods = [];

        foreach ($probes as $id => $probe) {
            $methods[] = sprintf(
                "    public function test_swap_probe_%d(): void\n    {\n        \$this->probe(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s);\n    }",
                $id,
                $id,
                var_export($probe['method'], true),
                var_export($probe['uri'], true),
                self::export($probe['params']),
                var_export($probe['payload'], true),
                var_export($probe['mode'], true),
                var_export($probe['ability'], true),
                var_export($probe['key'], true),
                var_export($probe['target'], true),
                self::export($probe['named']),
            );
        }

        $leaves = array_values(array_unique(array_column($probes, 'leaf')));
        $removed = array_values(array_unique(array_column(array_filter($probes, fn (array $probe) => $probe['mode'] === self::CHILDREN), 'leaf')));

        return strtr(<<<'PHP'
<?php

namespace Tests\Feature;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

class SwapProbeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Whose each record is, by its links to a user or a team.
     */
    private const OWNERS = __OWNERS__;

    /**
     * How a person joins each team.
     */
    private const TENANTS = __TENANTS__;

    private const USER = __USER__;

    private const RAISED = __RAISED__;

    /**
     * What hangs off each record the removals use.
     */
    private const CHILDREN = __CHILDREN__;

    private int $writes = 0;

    private bool $listening = false;

__METHODS__

    /**
     * Send one swap and note what each send did. A record the app's
     * factories could not make proves nothing.
     *
     * @param  list<array{name: string, model: string, field: string|null}>  $params
     * @param  list<string>  $named
     */
    private function probe(int $id, string $method, string $uri, array $params, ?string $payload, string $mode, ?string $ability, ?string $key, ?string $target, array $named): void
    {
        try {
            $seen = match ($mode) {
                'raise' => $this->raise($method, $uri, $params, (string) $payload, $named),
                'children' => $this->removal($method, $uri, $params),
                default => $this->exchange($method, $uri, $params, $payload, $mode, $ability, $key, $target),
            };
        } catch (Throwable) {
            $seen = ['broke' => true];
        }

        file_put_contents(base_path(__REPORT__), json_encode(['id' => $id, ...$seen]).PHP_EOL, FILE_APPEND);

        $this->addToAssertionCount(1);
    }

    /**
     * Make two people with records of their own, then send as the first:
     * with their own records, and with the second's. A form swap keeps the
     * address the first person's, puts the record in the key, and counts
     * the saved rows that point at it.
     *
     * @param  list<array{name: string, model: string, field: string|null}>  $params
     * @return array<string, mixed>
     */
    private function exchange(string $method, string $uri, array $params, ?string $payload, string $mode, ?string $ability, ?string $key, ?string $target): array
    {
        $this->listen();
        $leaf = $key === null ? $params[count($params) - 1]['model'] : (string) $payload;
        [$mine, $me] = $this->world($leaf);
        [$theirs, $them] = $this->world($leaf);

        if ($me === null || $them === null || $me->is($them)) {
            return ['owners' => false];
        }

        $body = $payload === null ? [] : $this->body($payload);

        $linked = fn (?Model $to) => $to === null ? null : ('App\\Models\\'.$payload)::query()->where((string) $key, $to->getKey())->count();
        $sent = function (string $url, ?Model $to = null) use ($method, $body, $key, $linked): array {
            $this->writes = 0;
            $before = $linked($to);
            $response = $this->call($method, $url, $to === null ? $body : [...$body, (string) $key => $to->getKey()]);
            $seen = ['status' => $response->getStatusCode(), 'invalid' => $response->getStatusCode() === 422 || session()->has('errors'), 'writes' => $this->writes, 'landed' => $to === null ? null : $linked($to) - $before];
            $this->flushSession();

            return $seen;
        };

        if ($key !== null) {
            $own = fn (int $index) => $mine;

            if (! isset($mine[$target], $theirs[$target])) {
                return ['owners' => false];
            }

            $this->actingAs($me);
            $control = $sent($this->address($uri, $params, $own), $mine[$target]);
            $this->actingAs($me);
            $swap = $sent($this->address($uri, $params, $own), $theirs[$target]);

            return ['owners' => true, 'control' => $control, 'swap' => $swap, 'guest' => null, 'policy' => null];
        }

        $last = count($params) - 1;
        $this->actingAs($me);
        $control = $sent($this->address($uri, $params, fn (int $index) => $mine));
        $policy = $ability !== null && Gate::getPolicyFor($theirs[$leaf]) !== null ? Gate::forUser($me)->allows($ability, $theirs[$leaf]) : null;
        $this->actingAs($me);
        $swap = $sent($this->address($uri, $params, fn (int $index) => $mode === 'all' || $index === $last ? $theirs : $mine));
        $guest = null;

        // A page anyone can open is shared on purpose.
        if ($method === 'GET') {
            $this->app['auth']->forgetGuards();
            $guest = $sent($this->address($uri, $params, fn (int $index) => $theirs))['status'];
        }

        return ['owners' => true, 'control' => $control, 'swap' => $swap, 'guest' => $guest, 'policy' => $policy];
    }

    /**
     * Send the person's own form twice: as it is, then with each extra
     * field the table has and the form does not ask for. A field counts
     * as saved when more rows hold the value sent after the send than
     * before, and the send without it did not save it too.
     *
     * @param  list<array{name: string, model: string, field: string|null}>  $params
     * @param  list<string>  $named
     * @return array<string, mixed>
     */
    private function raise(string $method, string $uri, array $params, string $payload, array $named): array
    {
        $this->listen();
        [$mine, $me] = $this->world($payload);

        if ($me === null) {
            return ['owners' => false];
        }

        $record = new ('App\\Models\\'.$payload);
        $table = $record->getTable();
        $extra = [];

        foreach (array_diff(array_intersect(self::RAISED, Schema::getColumnListing($table)), $named) as $field) {
            $cast = $record->getCasts()[$field] ?? null;
            $value = match (true) {
                is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class) => $cast::cases()[0]->value ?? null,
                $field === 'role' => preg_match('/char|text|string/i', Schema::getColumnType($table, $field)) === 1 ? 'admin' : null,
                $field === 'email_verified_at' => '2001-02-03 04:05:06',
                in_array($field, ['balance', 'credits'], true) => 987654,
                default => true,
            };

            if ($value !== null) {
                $extra[$field] = $value;
            }
        }

        // The form asks for every one of them, or the table has none.
        if ($extra === []) {
            return ['owners' => true, 'none' => true];
        }

        $body = $this->body($payload);
        $url = $this->address($uri, $params, fn (int $index) => $mine);
        $holding = fn () => array_map(fn (string $field) => DB::table($table)->where($field, $extra[$field])->count(), array_combine(array_keys($extra), array_keys($extra)));
        $sent = function (array $fields) use ($method, $url, $body, $holding): array {
            $this->writes = 0;
            $before = $holding();
            $response = $this->call($method, $url, [...$body, ...$fields]);
            $after = $holding();
            $seen = ['status' => $response->getStatusCode(), 'invalid' => $response->getStatusCode() === 422 || session()->has('errors'), 'writes' => $this->writes, 'landed' => null, 'raised' => array_keys(array_filter($after, fn (int $count, string $field) => $count > $before[$field], ARRAY_FILTER_USE_BOTH))];
            $this->flushSession();

            return $seen;
        };

        $this->actingAs($me);
        $control = $sent([]);
        $this->actingAs($me->fresh() ?? $me);
        $swap = $sent($extra);

        return ['owners' => true, 'control' => $control, 'swap' => $swap, 'guest' => null, 'policy' => null];
    }

    /**
     * Remove two of the person's own records: a bare one, then one with a
     * child of each kind that hangs off it, linked by a key the database
     * enforces. Only links the database checks are made: a link with no
     * foreign key, or one SQLite does not enforce, proves nothing.
     *
     * @param  list<array{name: string, model: string, field: string|null}>  $params
     * @return array<string, mixed>
     */
    private function removal(string $method, string $uri, array $params): array
    {
        $this->listen();
        $leaf = $params[count($params) - 1]['model'];
        [$bare, $me] = $this->world($leaf);
        [$full, $them] = $this->world($leaf);

        if ($me === null || $them === null) {
            return ['owners' => false];
        }

        // Soft deletes keep the row, so the links never come into it.
        if (method_exists($full[$leaf], 'trashed')) {
            return ['owners' => true, 'none' => true];
        }

        $connection = $full[$leaf]->getConnection();

        if ($connection->getDriverName() === 'sqlite' && (int) ($connection->selectOne('PRAGMA foreign_keys')->foreign_keys ?? 0) !== 1) {
            return ['owners' => false];
        }

        $made = [];

        foreach (self::CHILDREN[$leaf] ?? [] as $child) {
            $class = 'App\\Models\\'.$child['model'];
            // A link that removes or clears its children cannot block.
            $linked = array_filter(Schema::getForeignKeys((new $class)->getTable()), fn (array $link) => $link['columns'] === [$child['key']] && $link['foreign_table'] === $full[$leaf]->getTable() && ! in_array(strtolower((string) $link['on_delete']), ['cascade', 'set null'], true));

            if ($linked === [] || ! method_exists($class, 'factory')) {
                continue;
            }

            try {
                $class::factory()->create([$child['key'] => $full[$leaf]->getKey()]);
                $made[] = $child['relation'];
            } catch (Throwable) {
            }
        }

        if ($made === []) {
            return ['owners' => true, 'none' => true];
        }

        $sent = function (string $url) use ($method): array {
            $this->writes = 0;
            $response = $this->call($method, $url);
            $seen = ['status' => $response->getStatusCode(), 'invalid' => $response->getStatusCode() === 422 || session()->has('errors'), 'writes' => $this->writes, 'landed' => null, 'raised' => []];
            $this->flushSession();

            return [$seen, $response->exception === null ? null : class_basename($response->exception)];
        };

        $this->actingAs($me);
        [$control] = $sent($this->address($uri, $params, fn (int $index) => $bare));
        $this->actingAs($them);
        [$swap, $exception] = $sent($this->address($uri, $params, fn (int $index) => $full));

        return ['owners' => true, 'control' => $control, 'swap' => $swap, 'guest' => null, 'policy' => null, 'children' => $made, 'exception' => $exception];
    }

    /**
     * A valid form for a record, from the app's factory: no keys, no
     * records, and none of the extra fields.
     *
     * @return array<string, mixed>
     */
    private function body(string $payload): array
    {
        $raw = ('App\\Models\\'.$payload)::factory()->raw();

        return array_map(fn (mixed $value) => match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum => $value->value,
            default => $value,
        }, array_filter($raw, fn (mixed $value, string $key) => ! str_ends_with($key, '_id') && ! $value instanceof Model && ! in_array($key, self::RAISED, true), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Make a record with the app's factory, and its owner: the user at the
     * end of one of its links, who is also put in each team at the end of
     * one. Returns the records met on the way, by model, and the owner.
     *
     * @return array{array<string, Model>, Model|null}
     */
    private function world(string $leaf): array
    {
        $record = ('App\\Models\\'.$leaf)::factory()->create();
        $records = [$leaf => $record];
        $users = [];
        $teams = [];

        foreach (self::OWNERS[$leaf] ?? [] as $owner) {
            $at = $record;

            foreach ($owner['path'] as $step) {
                $at = $at instanceof Model ? $at->{$step['relation']} : null;

                if ($at instanceof Model) {
                    $records[$step['model']] ??= $at;
                }
            }

            if ($at instanceof Model) {
                $records[$owner['end'] === 'user' ? self::USER : $owner['end']] ??= $at;

                if ($owner['end'] === 'user') {
                    $users[] = $at;
                } else {
                    $teams[] = [$owner['end'], $at];
                }
            }
        }

        if ($users === [] && $teams === []) {
            return [$records, null];
        }

        $person = $users[0] ?? ('App\\Models\\'.self::USER)::factory()->create();

        foreach ($teams as [$tenant, $team]) {
            ['relation' => $relation, 'column' => $column, 'role' => $role] = self::TENANTS[$tenant];

            if (! $team->{$relation}()->whereKey($person->getKey())->exists()) {
                $team->{$relation}()->attach($person, $column === null ? [] : [$column => $role]);
            }
        }

        // The team in use, where the app keeps one as Laravel's starter kits do.
        if ($teams !== [] && Schema::hasColumn($person->getTable(), 'current_team_id')) {
            $person->forceFill(['current_team_id' => $teams[0][1]->getKey()])->save();
        }

        return [$records, $person->fresh()];
    }

    /**
     * Fill the address with the record each parameter takes from the
     * records given for it.
     *
     * @param  list<array{name: string, model: string, field: string|null}>  $params
     * @param  callable(int): array<string, Model>  $from
     */
    private function address(string $uri, array $params, callable $from): string
    {
        foreach ($params as $index => $param) {
            $record = $from($index)[$param['model']];
            $value = $param['field'] === null ? $record->getRouteKey() : $record->getAttribute($param['field']);
            $uri = (string) preg_replace('/\{'.$param['name'].'(:\w+)?\??\}/', (string) $value, $uri);
        }

        return $uri;
    }

    /**
     * Count what each send writes, leaving out the framework's own tables.
     */
    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        DB::listen(function ($query) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1 && preg_match('/\b(sessions|cache|cache_locks|jobs|job_batches|failed_jobs|password_reset_tokens)\b/', $query->sql) !== 1) {
                $this->writes++;
            }
        });
    }
}

PHP, [
            '__OWNERS__' => self::export(array_intersect_key($found['owners'], array_flip($leaves))),
            '__TENANTS__' => self::export($found['tenants']),
            '__USER__' => var_export($found['user'], true),
            '__RAISED__' => self::export(self::RAISED),
            '__CHILDREN__' => self::export(array_intersect_key($found['children'], array_flip($removed))),
            '__METHODS__' => implode("\n\n", $methods),
            '__REPORT__' => var_export($report, true),
        ]);
    }

    /**
     * Write a value as PHP on one line.
     */
    protected static function export(mixed $value): string
    {
        return (string) preg_replace('/\s+/', ' ', var_export($value, true));
    }

    /**
     * Read the report into what each swap did, by probe.
     *
     * @return array<int, Observed>
     */
    public static function parse(string $report): array
    {
        $observed = [];
        $sent = fn (mixed $value) => is_array($value) && is_int($value['status'] ?? null)
            ? ['status' => $value['status'], 'invalid' => ($value['invalid'] ?? false) === true, 'writes' => is_int($value['writes'] ?? null) ? $value['writes'] : 0, 'landed' => is_int($value['landed'] ?? null) ? $value['landed'] : null, 'raised' => array_values(array_intersect(self::RAISED, is_array($value['raised'] ?? null) ? $value['raised'] : []))]
            : null;

        foreach (preg_split('/\R/', trim($report)) ?: [] as $line) {
            $data = json_decode($line, true);

            if (is_array($data) && is_int($data['id'] ?? null)) {
                $observed[$data['id']] = [
                    'id' => $data['id'],
                    'owners' => ($data['owners'] ?? false) === true,
                    'broke' => ($data['broke'] ?? false) === true,
                    'none' => ($data['none'] ?? false) === true,
                    'control' => $sent($data['control'] ?? null),
                    'swap' => $sent($data['swap'] ?? null),
                    'guest' => is_int($data['guest'] ?? null) ? $data['guest'] : null,
                    'policy' => is_bool($data['policy'] ?? null) ? $data['policy'] : null,
                    'children' => array_values(array_filter(is_array($data['children'] ?? null) ? $data['children'] : [], fn (mixed $name) => is_string($name) && preg_match('/^\w+$/', $name) === 1)),
                    'exception' => is_string($data['exception'] ?? null) && preg_match('/^\w+$/', $data['exception']) === 1 ? $data['exception'] : null,
                ];
            }
        }

        return $observed;
    }

    /**
     * Judge each swap. The send with the person's own records must have
     * worked: a page that opened, or a send that wrote something and was
     * not turned down. Otherwise nothing can be told from the swap. A swap
     * that worked the same way is a finding, unless the app's own policy
     * lets the person do it or anyone can open the page: then it is shared
     * on purpose.
     *
     * @param  list<Probe>  $probes
     * @param  array<int, Observed>  $observed
     * @return Measured
     */
    public static function measure(array $probes, array $observed): array
    {
        $findings = [];
        $refused = 0;
        $shared = 0;
        $untried = 0;

        foreach ($probes as $id => $probe) {
            $seen = $observed[$id] ?? null;

            // No extra field to send: nothing was tried.
            if ($seen !== null && $seen['none']) {
                continue;
            }
            $control = $seen['control'] ?? null;
            $swap = $seen['swap'] ?? null;
            $reading = $probe['method'] === 'GET';
            $worked = fn (array $sent) => $reading
                ? $sent['status'] === 200
                : $sent['status'] < 400 && ! $sent['invalid'] && $sent['writes'] > 0;

            // A change that was taken but wrote nothing, like switching to
            // the team the person is already on, still opened the route.
            // Only a refusal of the swap can be judged from it then.
            $taken = ! $reading && $control !== null && $swap !== null && $control['status'] < 400 && ! $control['invalid'] && in_array($swap['status'], [403, 404], true);

            if ($seen === null || $control === null || $swap === null || (! $worked($control) && ! $taken) || ($swap['status'] >= 500 && $probe['mode'] !== self::CHILDREN)) {
                $untried++;

                continue;
            }

            if ($seen['policy'] === true || ($reading && $seen['guest'] === 200)) {
                $shared++;

                continue;
            }

            // A form swap worked only when a saved row now points at
            // their record; an app that keeps its own key wrote, too. An
            // extra field worked only when it was saved and the same form
            // without it did not save the same value.
            $raised = array_values(array_diff($swap['raised'], $control['raised']));

            if (match ($probe['mode']) {
                self::FIELD => $swap['status'] < 400 && ! $swap['invalid'] && ($swap['landed'] ?? 0) > 0,
                self::RAISE => $swap['status'] < 400 && ! $swap['invalid'] && $raised !== [],
                // Removed, or turned down with a message, are both answers.
                self::CHILDREN => $swap['status'] >= 500,
                default => $worked($swap),
            }) {
                $findings[] = [...$probe, 'status' => $swap['status'], 'raised' => $raised, 'children' => $seen['children'], 'exception' => $seen['exception']];
            } else {
                $refused++;
            }
        }

        return ['tried' => $refused + count($findings), 'refused' => $refused, 'shared' => $shared, 'findings' => $findings, 'untried' => $untried];
    }

    /**
     * Say what the swaps found, for the agent that repairs the change and
     * for the owner reading the check.
     *
     * @param  Measured  $measured
     */
    public static function describe(array $measured, int $skipped): string
    {
        $lines = [];

        foreach ($measured['findings'] as $finding) {
            if ($finding['mode'] === self::FIELD) {
                $lines[] = self::field($finding);

                continue;
            }

            if ($finding['mode'] === self::RAISE) {
                $lines[] = self::raised($finding);

                continue;
            }

            if ($finding['mode'] === self::CHILDREN) {
                $lines[] = self::removed($finding);

                continue;
            }

            $noun = AccessProbes::words($finding['leaf']);
            $whose = match ($finding['team']) {
                null => "another person's {$noun}",
                $finding['leaf'] => "a {$noun} they are not in",
                default => "a {$noun} of another ".AccessProbes::words($finding['team']),
            };
            $did = match ($finding['action']) {
                'view' => "could see {$whose}",
                'update' => $finding['method'] === 'GET' ? "could open the form to change {$whose}" : "could change {$whose}",
                'delete' => "could remove {$whose}",
                'create' => 'could add a '.AccessProbes::words((string) $finding['payload'])." to {$whose}",
                default => "could act on {$whose}",
            };

            if ($finding['mode'] === self::LEAF) {
                $parent = AccessProbes::words($finding['params'][count($finding['params']) - 2]['model']);
                $lines[] = "A signed-in person {$did}, through the address of their own {$parent}: {$finding['method']} {$finding['uri']} answered {$finding['status']}. Scope the route's bindings (Route::scopeBindings()), so the {$noun} must belong to the {$parent} in the address.";

                continue;
            }

            $lines[] = "A signed-in person {$did}: {$finding['method']} {$finding['uri']} answered {$finding['status']}. Make this route check the {$finding['leaf']} policy, or find the {$noun} through what the person may reach.";
        }

        $lines[] = "Tried {$measured['tried']} requests with someone else's records in the address or a form, or with fields the form does not ask for; {$measured['refused']} ".($measured['refused'] === 1 ? 'was' : 'were').' refused, as they should be.';

        if ($measured['shared'] > 0) {
            $lines[] = "{$measured['shared']} ".($measured['shared'] === 1 ? 'is' : 'are').' shared on purpose: the app\'s own policy allows it, or anyone can open the page.';
        }

        if ($measured['untried'] > 0) {
            $lines[] = "{$measured['untried']} could not be judged: the request did not work with the person's own records, or the records could not be made.";
        }

        if ($skipped > 0) {
            $lines[] = "{$skipped} ".($skipped === 1 ? 'route was' : 'routes were').' left out: a value in the address is not a record, or a record has no owner.';
        }

        return implode("\n", $lines);
    }

    /**
     * Say what a form swap that worked let the person do.
     *
     * @param  Finding  $finding
     */
    protected static function field(array $finding): string
    {
        $noun = AccessProbes::words((string) $finding['payload']);
        $target = (string) $finding['target'];
        $words = AccessProbes::words($target);
        $sent = "{$finding['method']} {$finding['uri']} saved one with {$finding['key']} set to";

        if ($finding['action'] === 'assign') {
            return "A signed-in person could save a {$noun} in another person's name: {$sent} them. Set {$finding['key']} from the signed-in person, not from the form.";
        }

        $whose = match ($finding['team']) {
            null => "another person's {$words}",
            $target => "a {$words} they are not in",
            default => "a {$words} of another ".AccessProbes::words($finding['team']),
        };
        $verb = $finding['action'] === 'create' ? "put a {$noun} in" : "move a {$noun} into";

        return "A signed-in person could {$verb} {$whose}: {$sent} it. Check that the {$words} the form names is one the person may reach, for example with the {$target} policy or an exists rule limited to what they may reach.";
    }

    /**
     * Say what an extra field that was saved let the person do.
     *
     * @param  Finding  $finding
     */
    protected static function raised(array $finding): string
    {
        $fields = implode(', ', $finding['raised']);
        $whose = $finding['params'] === [] && $finding['method'] !== 'POST' ? 'their own account' : 'a '.AccessProbes::words($finding['leaf']);
        $gives = match (true) {
            array_intersect($finding['raised'], ['balance', 'credits']) !== [] => 'set the money on',
            $finding['raised'] === ['email_verified_at'] => 'confirm the email of',
            default => 'give more rights to',
        };

        return "A signed-in person could {$gives} {$whose} by adding {$fields} to the form: {$finding['method']} {$finding['uri']} saved it. Save only the validated fields (\$request->validated()), and keep {$fields} out of the model's fillable attributes.";
    }

    /**
     * Say what broke when a record with children was removed.
     *
     * @param  Finding  $finding
     */
    protected static function removed(array $finding): string
    {
        $noun = AccessProbes::words($finding['leaf']);
        $children = implode(' or ', array_map(fn (string $relation) => AccessProbes::words(Str::singular($relation)), $finding['children']));
        $broke = $finding['exception'] === null ? '' : " ({$finding['exception']})";

        return "Removing a {$noun} that has a {$children} broke the page{$broke}: {$finding['method']} {$finding['uri']} answered {$finding['status']}, while one without them was removed. Decide what happens to them: remove them with it (cascadeOnDelete() on their foreign key, or delete them first in the same transaction), or turn the removal down with a message.";
    }
}
