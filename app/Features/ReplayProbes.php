<?php

namespace App\Features;

use App\Scaffolding\Scaffold;

/**
 * Send the same form twice (direction 32, the replay engine). People click
 * twice, go back and send again, or lose the answer on a slow line. The
 * second send must end in a message or a second record, never a broken
 * page. A form that adds a record with a column the database keeps unique,
 * and no rule that checks it first, breaks on the second send.
 *
 * Each probe signs in, builds the values with the record's factory, and
 * sends them to the route that adds it, twice. Only a second send that the
 * database refused as a duplicate is a finding: the exception says so,
 * and the column is kept, never the values. A first send that was turned
 * down or broke proves nothing, and a second record is not judged: the
 * app may want two.
 *
 * @phpstan-import-type Record from Scaffold
 *
 * @phpstan-type Probe array{record: string, noun: string, creator: string|null, uri: string}
 * @phpstan-type Observed array{id: int, first: int, added: bool, second: int, duplicate: string|null}
 * @phpstan-type Finding array{record: string, noun: string, creator: string|null, uri: string, duplicate: string}
 */
class ReplayProbes
{
    /**
     * Plan the probes: the route that adds each record the plan described,
     * and the route that adds each model the app had, where a controller
     * the change touched serves it.
     *
     * @param  list<Record>  $records
     * @param  list<string>  $models  The app's models, by class name
     * @param  list<string>  $controllers  Controllers the change touched, by class name
     * @return list<Probe>
     */
    public static function plan(array $records, array $models, string $routeList, array $controllers, int $limit): array
    {
        $routes = json_decode(trim($routeList), true);

        if (! is_array($routes) || ! array_is_list($routes)) {
            return [];
        }

        $planned = [];

        foreach ($records as $record) {
            $planned[$record['name']] = ['noun' => $record['label'] ?? AccessProbes::words($record['name']), 'creator' => Scaffold::creator($record['fields']), 'any' => true];
        }

        foreach ($models as $model) {
            $planned[$model] ??= ['noun' => AccessProbes::words($model), 'creator' => null, 'any' => false];
        }

        $probes = [];

        foreach ($planned as $model => $record) {
            foreach (AccessProbes::routesFor($model, $routes) as $route) {
                if ($route['action'] === 'create' && ($record['any'] || in_array($route['controller'], $controllers, true))) {
                    $probes[] = ['record' => $model, 'noun' => $record['noun'], 'creator' => $record['creator'], 'uri' => $route['uri']];
                }
            }
        }

        return array_slice($probes, 0, $limit);
    }

    /**
     * Write the test that sends each form twice and notes what came back.
     * It never fails on what it finds; the report is read back instead.
     *
     * @param  list<Probe>  $probes
     */
    public static function test(array $probes, string $report): string
    {
        $methods = [];

        foreach ($probes as $id => $probe) {
            $methods[] = sprintf(
                "    public function test_replay_%d(): void\n    {\n        \$this->replay(%d, %s, %s, %s);\n    }",
                $id,
                $id,
                var_export('App\\Models\\'.$probe['record'], true),
                var_export($probe['creator'], true),
                var_export($probe['uri'], true),
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplayProbeTest extends TestCase
{
    use RefreshDatabase;

{$methods}

    /**
     * Send the same values to the route that adds a record twice, as one
     * signed-in person, and note how each send ended.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  \$model
     */
    private function replay(int \$id, string \$model, ?string \$creator, string \$uri): void
    {
        \$this->actingAs(User::factory()->create());

        \$payload = array_map(fn (mixed \$value) => match (true) {
            \$value instanceof DateTimeInterface => \$value->format('Y-m-d H:i:s'),
            \$value instanceof BackedEnum => \$value->value,
            default => \$value,
        }, array_diff_key(\$model::factory()->raw(), array_flip(array_filter([\$creator]))));

        \$count = \$model::query()->count();
        \$first = \$this->call('POST', \$uri, \$payload);
        \$added = \$model::query()->count() > \$count && ! session()->has('errors');

        // After a refused duplicate some databases take no more queries in
        // this transaction, so nothing is read after the second send.
        \$second = \$added ? \$this->call('POST', \$uri, \$payload) : null;
        \$duplicate = null;

        if (\$second?->exception instanceof UniqueConstraintViolationException) {
            preg_match('/Key \(([^)]+)\)=|UNIQUE constraint failed: ([\w.]+(?:, [\w.]+)*)|for key \'([^\']+)\'/', \$second->exception->getMessage(), \$found);
            \$duplicate = implode('', array_slice(\$found, 1)) ?: '';
        }

        file_put_contents(base_path({$report}), json_encode([
            'id' => \$id,
            'first' => \$first->getStatusCode(),
            'added' => \$added,
            'second' => \$second?->getStatusCode() ?? 0,
            'duplicate' => \$duplicate,
        ]).PHP_EOL, FILE_APPEND);

        \$this->addToAssertionCount(1);
    }
}

PHP;
    }

    /**
     * Read the report the test wrote, one line per probe.
     *
     * @return array<int, Observed>
     */
    public static function parse(string $report): array
    {
        $observed = [];

        foreach (preg_split('/\R/', trim($report)) ?: [] as $line) {
            $data = json_decode($line, true);

            if (is_array($data) && is_int($data['id'] ?? null) && is_int($data['first'] ?? null) && is_int($data['second'] ?? null)) {
                $observed[$data['id']] = ['id' => $data['id'], 'first' => $data['first'], 'added' => ($data['added'] ?? false) === true, 'second' => $data['second'], 'duplicate' => is_string($data['duplicate'] ?? null) ? $data['duplicate'] : null];
            }
        }

        return $observed;
    }

    /**
     * Judge each probe whose first send added the record.
     *
     * @param  list<Probe>  $probes
     * @param  array<int, Observed>  $observed
     * @return array{findings: list<Finding>, tried: int}
     */
    public static function measure(array $probes, array $observed): array
    {
        $findings = [];
        $tried = 0;

        foreach ($probes as $id => $probe) {
            $seen = $observed[$id] ?? null;

            if ($seen === null || ! $seen['added'] || $seen['first'] >= 400 || $seen['second'] === 0) {
                continue;
            }

            $tried++;

            if ($seen['duplicate'] !== null) {
                $findings[] = [...$probe, 'duplicate' => $seen['duplicate']];
            }
        }

        return ['findings' => $findings, 'tried' => $tried];
    }

    /**
     * Say what the probes found, for the agent that repairs the change and
     * for the owner reading the check.
     *
     * @param  array{findings: list<Finding>, tried: int}  $measured
     */
    public static function describe(array $measured): string
    {
        $lines = [];

        foreach ($measured['findings'] as $finding) {
            $column = $finding['duplicate'] === '' ? '' : " ({$finding['duplicate']})";
            $lines[] = "Sending the form that adds a {$finding['noun']} twice broke the page: the second POST {$finding['uri']} answered 500, because the database already had a {$finding['noun']} with that value{$column}. Check it in the form request with a unique rule, so the person gets a message instead.";
        }

        $lines[] = sprintf('Sent %d %s twice as a signed-in person.', $measured['tried'], $measured['tried'] === 1 ? 'form' : 'forms');

        return implode("\n", $lines);
    }
}
