<?php

namespace App\Features;

use Illuminate\Support\Str;

/**
 * Try who may do what inside a team, before and after the change. The
 * access probes try people outside a team; these send each request of a
 * team's routes as each role the team gives its members too, so a member
 * who can now remove people, or an admin who can no longer change roles,
 * shows up as a measured fact.
 *
 * Roles are read the way Laravel declares them: the team's link to its
 * members has a pivot with a column cast to an enum, and each case is a
 * role. Routes are read from the controllers' own type hints: a route
 * whose bound values are the team, or the team and one of its members.
 *
 * Only what the request did counts. The team and its members are read
 * before and after; a request changed something when they differ, and a
 * page was seen when it answered 200. The same requests run on the
 * starting commit, so the result is what changed, not what is.
 *
 * Someone outside the team who can do something they could not do before
 * is a finding: crossing a team is always one (§26.10). A role that gained
 * or lost a thing is not, since a request may ask for exactly that. It is
 * told to the reviewer and the owner, who hold it against the plan.
 *
 * @phpstan-type Tenant array{model: string, relation: string, column: string, roles: list<string>}
 * @phpstan-type Route array{method: string, uri: string, name: string|null, tenant: string, team: string, member: string|null}
 * @phpstan-type Found array{tenants: list<Tenant>, routes: list<Route>}
 * @phpstan-type Probe array{route: string, method: string, uri: string, tenant: string, team: string, member: string|null, actor: string}
 * @phpstan-type Observed array{status: int, changed: bool, invalid: bool}
 * @phpstan-type Change array{route: string, actor: string, before: string, after: string}
 * @phpstan-type Measured array{tried: int, changed: list<Change>, new: array<string, array<string, string>>, findings: list<Change>}
 */
class RoleProbes
{
    public const GUEST = 'guest';

    public const STRANGER = 'stranger';

    public const YES = 'yes';

    public const NO = 'no';

    public const UNKNOWN = 'unknown';

    /**
     * The script that lists the app's teams with their roles, and the
     * routes that work on a team or on one of its members, run with the
     * app's own PHP.
     */
    public static function introspection(): string
    {
        return <<<'PHP'
<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = config('auth.providers.users.model');
$tenants = [];

foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
    $class = 'App\\Models\\'.basename($file, '.php');

    if (! class_exists($class) || ! is_subclass_of($class, Illuminate\Database\Eloquent\Model::class) || (new ReflectionClass($class))->isAbstract() || $class === $user) {
        continue;
    }

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $returns = $method->getReturnType();

        if ($method->class !== $class || $method->getNumberOfParameters() !== 0 || ! $returns instanceof ReflectionNamedType || ! is_a($returns->getName(), Illuminate\Database\Eloquent\Relations\BelongsToMany::class, true)) {
            continue;
        }

        try {
            $relation = (new $class)->{$method->name}();
        } catch (Throwable) {
            continue;
        }

        if (! $relation->getRelated() instanceof $user || isset($tenants[class_basename($class)])) {
            continue;
        }

        $pivot = $relation->getPivotClass();
        $casts = (new $pivot)->getCasts();

        foreach ($relation->getPivotColumns() as $column) {
            $cast = $casts[$column] ?? null;

            if (is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
                $tenants[class_basename($class)] = ['model' => class_basename($class), 'relation' => $method->name, 'column' => $column, 'roles' => array_map(fn ($case) => (string) $case->value, $cast::cases())];

                break;
            }
        }
    }
}

$routes = [];

foreach (app('router')->getRoutes() as $route) {
    if ($route->getDomain() !== null || ! is_string($route->getAction('uses'))) {
        continue;
    }

    $bound = [];

    foreach ($route->signatureParameters(['subClass' => Illuminate\Contracts\Routing\UrlRoutable::class]) as $parameter) {
        $bound[$parameter->getName()] = class_basename((string) $parameter->getType()?->getName());
    }

    $names = $route->parameterNames();
    $tenant = $bound[$names[0] ?? ''] ?? null;

    if ($tenant === null || ! isset($tenants[$tenant]) || count($names) > 2 || (isset($names[1]) && ($bound[$names[1]] ?? null) !== class_basename($user))) {
        continue;
    }

    foreach (array_diff($route->methods(), ['HEAD']) as $method) {
        $routes[] = ['method' => $method, 'uri' => '/'.ltrim($route->uri(), '/'), 'name' => $route->getName(), 'tenant' => $tenant, 'team' => $names[0], 'member' => $names[1] ?? null];
    }
}

echo json_encode(['tenants' => array_values($tenants), 'routes' => $routes]), "\n";

PHP;
    }

    /**
     * Read what introspection() printed, or null when it printed nothing
     * usable.
     *
     * @return Found|null
     */
    public static function found(string $output): ?array
    {
        $data = json_decode(trim((string) Str::of($output)->trim()->explode("\n")->last()), true);

        if (! is_array($data) || ! is_array($data['tenants'] ?? null) || ! is_array($data['routes'] ?? null)) {
            return null;
        }

        $word = fn (mixed $value) => is_string($value) && preg_match('/^\w+$/', $value) === 1;
        $tenants = [];

        foreach ($data['tenants'] as $tenant) {
            $roles = is_array($tenant['roles'] ?? null) ? array_values(array_filter($tenant['roles'], fn (mixed $role) => is_string($role) && preg_match('/^[\w-]+$/', $role) === 1)) : [];

            if (is_array($tenant) && $word($tenant['model'] ?? null) && $word($tenant['relation'] ?? null) && $word($tenant['column'] ?? null) && $roles !== []) {
                $tenants[] = ['model' => $tenant['model'], 'relation' => $tenant['relation'], 'column' => $tenant['column'], 'roles' => $roles];
            }
        }

        $models = array_column($tenants, 'model');
        $routes = [];

        foreach ($data['routes'] as $route) {
            if (is_array($route) && in_array($route['method'] ?? null, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) && is_string($route['uri'] ?? null)
                && in_array($route['tenant'] ?? null, $models, true) && $word($route['team'] ?? null) && (($route['member'] ?? null) === null || $word($route['member']))) {
                $routes[] = ['method' => $route['method'], 'uri' => $route['uri'], 'name' => is_string($route['name'] ?? null) ? $route['name'] : null, 'tenant' => $route['tenant'], 'team' => $route['team'], 'member' => $route['member'] ?? null];
            }
        }

        return ['tenants' => $tenants, 'routes' => $routes];
    }

    /**
     * Plan one probe per route and actor: a signed-out visitor, a signed-in
     * person outside the team, and a member holding each role.
     *
     * @param  Found  $found
     * @return list<Probe>
     */
    public static function plan(array $found, int $limit): array
    {
        $roles = array_column($found['tenants'], 'roles', 'model');
        $probes = [];

        foreach ($found['routes'] as $route) {
            foreach ([self::GUEST, self::STRANGER, ...array_map(fn (string $role) => "role:{$role}", $roles[$route['tenant']])] as $actor) {
                $probes[] = [
                    'route' => self::label($route),
                    ...array_intersect_key($route, array_flip(['method', 'uri', 'tenant', 'team', 'member'])),
                    'actor' => $actor,
                ];
            }
        }

        return array_slice($probes, 0, $limit);
    }

    /**
     * Name a route for people: its name when it has one.
     *
     * @param  array{method: string, uri: string, name: string|null}  $route
     */
    public static function label(array $route): string
    {
        return $route['name'] ?? "{$route['method']} {$route['uri']}";
    }

    /**
     * Write the test that sends each probe and notes what it did. It never
     * fails on what it finds; the report is read back instead. A member a
     * route works on holds the last role, the one with the least rights by
     * the usual order of an enum's cases; a role sent to change it is the
     * one before it.
     *
     * @param  list<Probe>  $probes
     * @param  list<Tenant>  $tenants
     */
    public static function test(array $probes, array $tenants, string $report): string
    {
        $methods = [];

        foreach ($probes as $id => $probe) {
            $methods[] = sprintf(
                "    public function test_role_probe_%d(): void\n    {\n        \$this->probe(%d, %s, %s, %s, %s, %s, %s);\n    }",
                $id,
                $id,
                var_export($probe['method'], true),
                var_export($probe['uri'], true),
                var_export($probe['tenant'], true),
                var_export($probe['team'], true),
                var_export($probe['member'], true),
                var_export($probe['actor'], true),
            );
        }

        $methods = implode("\n\n", $methods);
        $tenants = var_export(array_column($tenants, null, 'model'), true);
        $report = var_export($report, true);

        return <<<PHP
<?php

namespace Tests\Feature;

use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleProbeTest extends TestCase
{
    use RefreshDatabase;

    private const TENANTS = {$tenants};

{$methods}

    /**
     * Send one request as one actor and note what it did to the team and
     * its members.
     */
    private function probe(int \$id, string \$method, string \$uri, string \$tenant, string \$teamParam, ?string \$memberParam, string \$actor): void
    {
        ['relation' => \$relation, 'column' => \$column, 'roles' => \$roles] = self::TENANTS[\$tenant];
        \$model = 'App\\\\Models\\\\'.\$tenant;
        \$team = \$model::factory()->create();
        \$user = match (true) {
            \$actor === 'guest' => null,
            default => User::factory()->create(),
        };

        if (\$user !== null && str_starts_with(\$actor, 'role:')) {
            \$team->{\$relation}()->attach(\$user, [\$column => substr(\$actor, 5)]);
        }

        \$member = null;
        \$payload = [];

        if (\$memberParam !== null) {
            \$member = User::factory()->create();
            \$team->{\$relation}()->attach(\$member, [\$column => \$roles[count(\$roles) - 1]]);
            \$payload = in_array(\$method, ['PUT', 'PATCH'], true) ? [\$column => \$roles[max(0, count(\$roles) - 2)]] : [];
        } elseif (in_array(\$method, ['PUT', 'PATCH', 'POST'], true)) {
            \$payload = array_map(fn (mixed \$value) => match (true) {
                \$value instanceof DateTimeInterface => \$value->format('Y-m-d H:i:s'),
                \$value instanceof BackedEnum => \$value->value,
                default => \$value,
            }, \$model::factory()->raw());
        }

        \$pivot = \$team->{\$relation}()->getPivotAccessor();
        \$read = fn () => [
            \$model::query()->whereKey(\$team->getKey())->first()?->getAttributes(),
            \$team->{\$relation}()->get()->map(function (Model \$person) use (\$pivot, \$column) {
                \$role = \$person->{\$pivot}->{\$column};

                return [\$person->getKey(), (string) (\$role instanceof BackedEnum ? \$role->value : \$role)];
            })->sortBy(0)->values()->all(),
        ];
        \$before = \$read();

        \$url = (string) preg_replace('/\{'.\$teamParam.'(:\w+)?\??\}/', (string) \$team->getRouteKey(), \$uri);

        if (\$member !== null) {
            \$url = (string) preg_replace('/\{'.\$memberParam.'(:\w+)?\??\}/', (string) \$member->getRouteKey(), \$url);
        }

        if (\$user !== null) {
            \$this->actingAs(\$user);
        }

        \$response = \$this->call(\$method, \$url, \$payload);
        \$after = \$read();
        \$without = fn (array \$state) => [\$state[0] === null ? null : array_diff_key(\$state[0], ['updated_at' => true]), \$state[1]];

        file_put_contents(base_path({$report}), json_encode([
            'id' => \$id,
            'status' => \$response->getStatusCode(),
            'changed' => \$without(\$before) != \$without(\$after),
            'invalid' => \$response->getStatusCode() === 422 || session()->has('errors'),
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
                $observed[$data['id']] = ['status' => $data['status'], 'changed' => ($data['changed'] ?? false) === true, 'invalid' => ($data['invalid'] ?? false) === true];
            }
        }

        return $observed;
    }

    /**
     * Say whether a probe's actor could do it: yes when it changed the team
     * or its members, or saw the page; no when it was refused or turned
     * down; unknown when the page broke or nothing was noted.
     *
     * @param  Probe  $probe
     * @param  Observed|null  $seen
     */
    public static function outcome(array $probe, ?array $seen): string
    {
        return match (true) {
            $seen === null || $seen['status'] >= 500 => self::UNKNOWN,
            $probe['method'] === 'GET' => $seen['status'] === 200 ? self::YES : self::NO,
            default => $seen['changed'] ? self::YES : self::NO,
        };
    }

    /**
     * Compare what each actor could do before and after the change. A route
     * the starting commit did not have is new: what each actor can do there
     * is listed, not compared.
     *
     * @param  list<Probe>  $probes
     * @param  array<int, Observed>  $after
     * @param  array<int, Observed>  $before
     * @param  list<string>  $routesBefore  The routes the starting commit had, by label
     * @return Measured
     */
    public static function measure(array $probes, array $after, array $before, array $routesBefore): array
    {
        $changed = [];
        $new = [];
        $findings = [];
        $tried = 0;

        foreach ($probes as $id => $probe) {
            $now = self::outcome($probe, $after[$id] ?? null);

            if ($now === self::UNKNOWN) {
                continue;
            }

            $tried++;

            if (! in_array($probe['route'], $routesBefore, true)) {
                $new[$probe['route']][$probe['actor']] = $now;

                if ($now === self::YES && ! str_starts_with($probe['actor'], 'role:')) {
                    $findings[] = ['route' => $probe['route'], 'actor' => $probe['actor'], 'before' => self::UNKNOWN, 'after' => $now];
                }

                continue;
            }

            $then = self::outcome($probe, $before[$id] ?? null);

            if ($then === self::UNKNOWN || $then === $now) {
                continue;
            }

            $change = ['route' => $probe['route'], 'actor' => $probe['actor'], 'before' => $then, 'after' => $now];
            $changed[] = $change;

            if ($now === self::YES && ! str_starts_with($probe['actor'], 'role:')) {
                $findings[] = $change;
            }
        }

        return ['tried' => $tried, 'changed' => $changed, 'new' => $new, 'findings' => $findings];
    }

    /**
     * Say who an actor is, in words.
     */
    public static function who(string $actor): string
    {
        return match (true) {
            $actor === self::GUEST => 'someone who is not signed in',
            $actor === self::STRANGER => 'a signed-in person outside the team',
            default => 'a member with the '.Str::after($actor, 'role:').' role',
        };
    }

    /**
     * Say what changed, for the agent that repairs the change, the
     * reviewer and the owner reading the check.
     *
     * @param  Measured  $measured
     */
    public static function describe(array $measured): string
    {
        $lines = [];

        foreach ($measured['findings'] as $finding) {
            $lines[] = ucfirst(self::who($finding['actor']))." can now use {$finding['route']}. Nobody outside a team may reach it or its members. Make this route check that the person belongs to the team.";
        }

        $roles = array_filter($measured['changed'], fn (array $change) => str_starts_with($change['actor'], 'role:'));

        if ($roles !== []) {
            $lines[] = "What members can do changed:\n".implode("\n", array_map(
                fn (array $change) => '- '.ucfirst(self::who($change['actor'])).($change['after'] === self::YES ? ' can now use ' : ' can no longer use ').$change['route'],
                $roles,
            ));
        }

        foreach ($measured['new'] as $route => $actors) {
            $can = array_keys(array_filter($actors, fn (string $outcome) => $outcome === self::YES));
            $lines[] = "New: {$route}. ".($can === [] ? 'Nobody tried could use it.' : 'Could use it: '.implode(', ', array_map(self::who(...), $can)).'.');
        }

        $lines[] = "Tried {$measured['tried']} requests as each role in the team and as people outside it, on the change and on the app before it.";

        return implode("\n", $lines);
    }
}
