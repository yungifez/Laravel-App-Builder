<?php

namespace App\Features;

use Illuminate\Support\Arr;

/**
 * Send wrong values to the app's forms (§26.11, "rules are generators").
 * For each route that takes input on a controller the change touched, a
 * first test sends an empty form as a signed-in person and notes the rules
 * the app's validator was given, form request or validate() alike. Each
 * field then gets its classes: one valid value, left out, each rule's
 * edge on both sides, one value of the wrong kind, and one outside its
 * choices or rows. A probe changes one field of a form that was first
 * accepted whole; fields are combined only where a rule links them.
 *
 * Only what came back counts. A wrong value the app took, a valid one it
 * turned down, or a page that broke is a finding. It blocks only when the
 * change added the field or its rules; otherwise it is reported as what
 * the app already did. Rules written as code, and rules that depend on
 * other fields, are tried only for presence and listed as such.
 *
 * @phpstan-type Route array{method: string, uri: string, action: string}
 * @phpstan-type Rules array{id: int, status: int, source: string, fields: array<string, list<string>>, reason: string|null}
 * @phpstan-type Probe array{route: int, field: string, key: string, expect: string, payload: array<string, mixed>, says: string}
 * @phpstan-type Coverage array{route: int, field: string, reason: string}
 * @phpstan-type Planned array{baselines: array<int, array<string, mixed>>, probes: list<Probe>, coverage: list<Coverage>}
 * @phpstan-type Observed array{status: int, errors: list<string>, exception: string|null, reason: string|null}
 * @phpstan-type Finding array{route: string, field: string, says: string, outcome: string, exception: string|null}
 */
class InputProbes
{
    /**
     * Rules that make a field's presence depend on other fields.
     */
    public const CONDITIONS = ['required_if', 'required_unless', 'required_with', 'required_with_all', 'required_without', 'required_without_all', 'required_if_accepted', 'required_if_declined', 'present_if', 'present_unless', 'present_with', 'present_with_all', 'sometimes', 'exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without'];

    /**
     * Rules after which a field must not be sent at all.
     */
    public const LEFT_OUT = ['prohibited', 'prohibited_if', 'prohibited_unless', 'missing', 'missing_if', 'missing_unless', 'missing_with', 'missing_with_all', 'exclude'];

    /**
     * Dates the rules may compare with, in days from today.
     */
    protected const DAYS = ['today' => 0, 'now' => 0, 'tomorrow' => 1, 'yesterday' => -1];

    /**
     * Get the routes that take input on the controllers the change touched.
     *
     * @param  list<string>  $controllers  By class name
     * @return list<Route>
     */
    public static function routes(string $routeList, array $controllers): array
    {
        $routes = json_decode(trim($routeList), true);
        $found = [];

        foreach (is_array($routes) && array_is_list($routes) ? $routes : [] as $route) {
            $action = is_array($route) && is_string($route['action'] ?? null) ? $route['action'] : '';
            $method = collect(explode('|', is_string($route['method'] ?? null) ? $route['method'] : ''))->first(fn (string $method) => in_array($method, ['POST', 'PUT', 'PATCH'], true));
            $uri = is_string($route['uri'] ?? null) ? $route['uri'] : '';

            if ($method !== null && $uri !== '' && in_array(explode('@', $action)[0], $controllers, true)) {
                $found["{$method} {$uri}"] = ['method' => $method, 'uri' => $uri, 'action' => $action];
            }
        }

        return array_values($found);
    }

    /**
     * Get what the change did to each file across its rounds: whether it
     * added the file, and the lines it added.
     *
     * @param  list<string|null>  $patches  Oldest first
     * @return array<string, array{new: bool, lines: list<string>}>
     */
    public static function changed(array $patches): array
    {
        $changed = [];

        foreach ($patches as $patch) {
            foreach (PatchSummary::files($patch) as $file) {
                $changed[$file['path']] = [
                    'new' => ($changed[$file['path']]['new'] ?? false) || preg_match('/^new file mode /m', $file['diff']) === 1,
                    'lines' => [...$changed[$file['path']]['lines'] ?? [], ...array_column(PatchSummary::addedLines($file['diff']), 'text')],
                ];
            }
        }

        return $changed;
    }

    /**
     * Write the test that notes each route's rules. It never fails on what
     * it finds; the report is read back instead.
     *
     * @param  list<Route>  $routes
     */
    public static function rulesTest(array $routes, string $report): string
    {
        $methods = [];

        foreach ($routes as $id => $route) {
            $methods[] = sprintf("    public function test_rules_%d(): void\n    {\n        \$this->rules(%d, %s, %s, %s);\n    }", $id, $id, var_export($route['method'], true), var_export($route['uri'], true), var_export($route['action'], true));
        }

        return self::testClass('InputRulesProbeTest', implode("\n\n", $methods), $report, <<<'PHP'
    /**
     * Send an empty form as a signed-in person and note the rules the
     * validator was given, as text.
     */
    private function rules(int $id, string $method, string $uri, string $action): void
    {
        $line = ['id' => $id, 'status' => 0, 'source' => '', 'fields' => [], 'reason' => null];

        try {
            [$class, $name] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
            $line['source'] = $this->source($class, $name);
            $seen = [];
            $this->app['validator']->resolver(function ($translator, $data, $rules, $messages, $attributes) use (&$seen) {
                foreach ($rules as $field => $rule) {
                    $seen[(string) $field] ??= array_values(array_filter(array_map($this->text(...), is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : [$rule])), fn (string $rule) => $rule !== ''));
                }

                return new Validator($translator, $data, $rules, $messages, $attributes);
            });
            $response = $this->send($method, $uri, []);
            $line['status'] = $response->getStatusCode();
            $line['fields'] = $seen;
            $line['reason'] = $seen !== [] ? null : (in_array($line['status'], [401, 403, 404, 405, 419], true) ? 'turned_away' : 'no_rules');
        } catch (Throwable $exception) {
            $line['reason'] = $this->reason($exception);
        }

        $this->note($line);
    }

    /**
     * Get the file that holds a route's rules: its form request, or else
     * its controller.
     */
    private function source(string $class, string $name): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        foreach ((new ReflectionMethod($class, $name))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                $file = (new ReflectionClass($type->getName()))->getFileName();
            }
        }

        return ltrim(str_replace(base_path(), '', (string) $file), '/');
    }

    /**
     * Write one rule as text. A rule written as code says only what it is.
     */
    private function text(mixed $rule): string
    {
        return match (true) {
            is_string($rule) => $rule,
            $rule instanceof Closure => 'closure',
            $rule instanceof ConditionalRules, $rule instanceof RequiredIf => 'conditional',
            $rule instanceof Enum => 'in:'.implode(',', array_map(fn ($case) => $case instanceof BackedEnum ? $case->value : $case->name, (fn () => $this->type::cases())->call($rule))),
            $rule instanceof Stringable || (is_object($rule) && method_exists($rule, '__toString')) => (string) $rule,
            is_object($rule) => 'custom:'.class_basename($rule),
            default => '',
        };
    }
PHP);
    }

    /**
     * Read the rules the first test noted, one line per route.
     *
     * @return array<int, Rules>
     */
    public static function rules(string $report): array
    {
        $rules = [];

        foreach (preg_split('/\R/', trim($report)) ?: [] as $line) {
            $data = json_decode($line, true);

            if (! is_array($data) || ! is_int($data['id'] ?? null) || ! is_array($data['fields'] ?? null)) {
                continue;
            }

            $fields = [];

            foreach ($data['fields'] as $field => $list) {
                if (is_array($list) && array_is_list($list)) {
                    $fields[(string) $field] = array_values(array_filter($list, is_string(...)));
                }
            }

            $rules[$data['id']] = [
                'id' => $data['id'],
                'status' => is_int($data['status'] ?? null) ? $data['status'] : 0,
                'source' => is_string($data['source'] ?? null) ? $data['source'] : '',
                'fields' => $fields,
                'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : null,
            ];
        }

        return $rules;
    }

    /**
     * Plan the probes: a whole form each route accepts, then one changed
     * field per probe, most telling classes first, up to the limit.
     *
     * @param  list<Route>  $routes
     * @param  array<int, Rules>  $rules
     * @return Planned
     */
    public static function plan(array $routes, array $rules, int $limit): array
    {
        $baselines = [];
        $candidates = [];
        $coverage = [];

        foreach ($routes as $id => $route) {
            $found = $rules[$id] ?? null;

            if ($found === null || $found['reason'] !== null || $found['fields'] === []) {
                $coverage[] = ['route' => $id, 'field' => '', 'reason' => $found['reason'] ?? 'not_run'];

                continue;
            }

            $form = self::form($found['fields']);

            if (is_string($form)) {
                $coverage[] = ['route' => $id, 'field' => '', 'reason' => $form];

                continue;
            }

            $baselines[$id] = $form['payload'];
            array_push($coverage, ...array_map(fn (array $item) => ['route' => $id, ...$item], $form['coverage']));

            foreach ($form['probes'] as $probe) {
                $candidates[] = ['route' => $id, ...$probe];
            }
        }

        // The most telling classes first, across every form, so a small
        // limit still tries each field once.
        usort($candidates, fn (array $a, array $b) => [$a['rank'], $a['route']] <=> [$b['rank'], $b['route']]);
        $probes = array_map(fn (array $probe) => ['route' => $probe['route'], 'field' => $probe['field'], 'key' => $probe['key'], 'expect' => $probe['expect'], 'payload' => $probe['payload'], 'says' => $probe['says']], array_slice($candidates, 0, max(0, $limit)));
        $tried = array_fill_keys(array_map(fn (array $probe) => $probe['route'], $probes), true);

        return ['baselines' => array_intersect_key($baselines, $tried), 'probes' => $probes, 'coverage' => $coverage];
    }

    /**
     * Write the test that sends each whole form, then each probe, and
     * notes what came back.
     *
     * @param  list<Route>  $routes
     * @param  Planned  $planned
     */
    public static function test(array $routes, array $planned, string $report): string
    {
        $methods = [];

        foreach ($planned['baselines'] as $id => $payload) {
            $methods[] = sprintf("    public function test_form_%d(): void\n    {\n        \$this->probe('form', %d, %s, %s, %s, '');\n    }", $id, $id, var_export($routes[$id]['method'], true), var_export($routes[$id]['uri'], true), self::export($payload));
        }

        foreach ($planned['probes'] as $id => $probe) {
            $methods[] = sprintf("    public function test_probe_%d(): void\n    {\n        \$this->probe('probe', %d, %s, %s, %s, %s);\n    }", $id, $id, var_export($routes[$probe['route']]['method'], true), var_export($routes[$probe['route']]['uri'], true), self::export($probe['payload']), var_export($probe['field'], true));
        }

        return self::testClass('InputProbeTest', implode("\n\n", $methods), $report, <<<'PHP'
    /** @var array<string, mixed> */
    private array $rows = [];

    /**
     * Send one form as a signed-in person and note how it ended: the
     * status, the fields it named as wrong, and what broke, if anything.
     *
     * @param  array<string, mixed>  $payload
     */
    private function probe(string $kind, int $id, string $method, string $uri, array $payload, string $field): void
    {
        $line = ['kind' => $kind, 'id' => $id, 'status' => 0, 'errors' => [], 'exception' => null, 'reason' => null];

        try {
            $response = $this->send($method, $uri, $this->fill($payload));
            // The validator's own answer when the app let it through;
            // otherwise what the app put in the session or its JSON.
            $errors = $response->exception instanceof ValidationException ? $response->exception->errors() : [];
            $bag = session('errors');
            $bag = $bag instanceof ViewErrorBag ? $bag->getBag('default') : $bag;
            $errors = [...$errors, ...($bag instanceof MessageBag ? $bag->toArray() : [])];

            if ($response->getStatusCode() === 422 && is_array($response->json('errors'))) {
                $errors = [...$errors, ...$response->json('errors')];
            }

            $errors = array_keys($errors);

            $line['status'] = $response->getStatusCode();
            $line['errors'] = array_values(array_unique(array_map(strval(...), $errors)));
            $line['exception'] = $response->exception === null || $response->exception instanceof ValidationException ? null : class_basename($response->exception);
        } catch (Throwable $exception) {
            $line['reason'] = $this->reason($exception);
        }

        $this->note($line);
    }

    /**
     * Fill in the values only the app can make: a row that exists, a date
     * counted from today, and a fake upload.
     */
    private function fill(mixed $value): mixed
    {
        if (is_array($value) && isset($value['@date'])) {
            return now()->addDays($value['@date'])->format($value['format']);
        }

        if (is_array($value) && isset($value['@file'])) {
            return UploadedFile::fake()->create('probe.'.$value['@file'], $value['kb'], $value['mime']);
        }

        if (is_array($value) && isset($value['@exists'])) {
            $model = 'App\\Models\\'.Str::studly(Str::singular($value['@exists']));

            if (! class_exists($model) || ! method_exists($model, 'factory')) {
                throw new RuntimeException('no_exists:'.$value['@exists']);
            }

            return ($this->rows[$value['@exists']] ??= $model::factory()->create())->{$value['column']};
        }

        return is_array($value) ? array_map($this->fill(...), $value) : $value;
    }
PHP);
    }

    /**
     * Read what the probe test noted.
     *
     * @return array{forms: array<int, Observed>, probes: array<int, Observed>}
     */
    public static function parse(string $report): array
    {
        $observed = ['forms' => [], 'probes' => []];

        foreach (preg_split('/\R/', trim($report)) ?: [] as $line) {
            $data = json_decode($line, true);

            if (! is_array($data) || ! in_array($data['kind'] ?? null, ['form', 'probe'], true) || ! is_int($data['id'] ?? null) || ! is_int($data['status'] ?? null)) {
                continue;
            }

            $observed[$data['kind'] === 'form' ? 'forms' : 'probes'][$data['id']] = [
                'status' => $data['status'],
                'errors' => is_array($data['errors'] ?? null) ? array_values(array_filter($data['errors'], is_string(...))) : [],
                'exception' => is_string($data['exception'] ?? null) ? $data['exception'] : null,
                'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : null,
            ];
        }

        return $observed;
    }

    /**
     * Judge each probe on a form that was accepted whole. A finding blocks
     * when the change added its field or its rules: the file holding the
     * rules is new, or one of its added lines names the field.
     *
     * @param  list<Route>  $routes
     * @param  array<int, Rules>  $rules
     * @param  Planned  $planned
     * @param  array{forms: array<int, Observed>, probes: array<int, Observed>}  $observed
     * @param  array<string, array{new: bool, lines: list<string>}>  $changed  The change's files, by path
     * @return array{findings: list<Finding>, existing: list<Finding>, coverage: list<Coverage>, tried: int, forms: int}
     */
    public static function measure(array $routes, array $rules, array $planned, array $observed, array $changed): array
    {
        $coverage = $planned['coverage'];
        $accepted = [];

        foreach (array_keys($planned['baselines']) as $id) {
            $form = $observed['forms'][$id] ?? null;
            $reason = match (true) {
                $form === null => 'not_run',
                $form['reason'] !== null => $form['reason'],
                $form['status'] >= 500 => 'form_broke:'.($form['exception'] ?? $form['status']),
                $form['errors'] !== [] => 'form_refused:'.implode(', ', $form['errors']),
                $form['status'] >= 400 => 'turned_away:'.$form['status'],
                default => null,
            };

            if ($reason === null) {
                $accepted[$id] = true;
            } else {
                $coverage[] = ['route' => $id, 'field' => '', 'reason' => $reason];
            }
        }

        $findings = [];
        $existing = [];
        $tried = 0;

        foreach ($planned['probes'] as $id => $probe) {
            if (! isset($accepted[$probe['route']])) {
                continue;
            }

            $seen = $observed['probes'][$id] ?? null;
            $named = $seen !== null && in_array($probe['field'], $seen['errors'], true);
            $outcome = match (true) {
                $seen === null => 'not_run',
                $seen['reason'] !== null => $seen['reason'],
                $seen['status'] >= 500 => 'broke',
                $probe['expect'] === 'refuse' && $named, $probe['expect'] === 'accept' && $seen['errors'] === [] && $seen['status'] < 400 => 'held',
                $probe['expect'] === 'accept' && $named => 'refused',
                $seen['errors'] !== [] => 'other_field',
                $seen['status'] >= 400 => 'turned_away:'.$seen['status'],
                default => 'accepted',
            };

            if (! in_array($outcome, ['held', 'broke', 'refused', 'accepted'], true)) {
                $coverage[] = ['route' => $probe['route'], 'field' => $probe['field'], 'reason' => $outcome];

                continue;
            }

            $tried++;

            if ($outcome === 'held') {
                continue;
            }

            $route = $routes[$probe['route']];
            $finding = ['route' => "{$route['method']} /".ltrim($route['uri'], '/'), 'field' => $probe['field'], 'says' => $probe['says'], 'outcome' => $outcome, 'exception' => $outcome === 'broke' ? ($seen['exception'] ?? null) : null];
            $source = $changed[$rules[$probe['route']]['source'] ?? ''] ?? null;
            $added = $source !== null && ($source['new'] || array_any($source['lines'], fn (string $line) => preg_match('/[\'"]'.preg_quote($probe['key'], '/').'[\'"]/', $line) === 1));

            if ($added) {
                $findings[] = $finding;
            } else {
                $existing[] = $finding;
            }
        }

        return ['findings' => $findings, 'existing' => $existing, 'coverage' => $coverage, 'tried' => $tried, 'forms' => count($accepted)];
    }

    /**
     * Say what the probes found, for the agent that repairs the change and
     * for the owner reading the check.
     *
     * @param  list<Route>  $routes
     * @param  array{findings: list<Finding>, existing: list<Finding>, coverage: list<Coverage>, tried: int, forms: int}  $measured
     */
    public static function describe(array $routes, array $measured): string
    {
        $lines = array_map(self::finding(...), $measured['findings']);

        if ($measured['existing'] !== []) {
            $lines[] = 'Already so before this change, so not sent back:';
            array_push($lines, ...array_map(fn (array $finding) => '- '.self::finding($finding), $measured['existing']));
        }

        $lines[] = sprintf('Tried %d %s on %d %s.', $measured['tried'], $measured['tried'] === 1 ? 'value' : 'values', $measured['forms'], $measured['forms'] === 1 ? 'form' : 'forms');
        $covered = array_values(array_unique(array_map(function (array $item) use ($routes) {
            $route = isset($routes[$item['route']]) ? "{$routes[$item['route']]['method']} /".ltrim($routes[$item['route']]['uri'], '/') : '';

            return trim("{$route} ".($item['field'] === '' ? '' : "{$item['field']}: ").self::reason($item['reason']));
        }, $measured['coverage'])));

        if ($covered !== []) {
            $lines[] = 'Not fully tried:';
            array_push($lines, ...array_map(fn (string $line) => "- {$line}", array_slice($covered, 0, 10)));

            if (count($covered) > 10) {
                $lines[] = sprintf('- and %d more.', count($covered) - 10);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build one form a route should accept whole, and the probes on it, or
     * say why it cannot be filled in. A field inside a list is reached by
     * its first item ("rooms.*.name" is sent as "rooms.0.name"); the list
     * itself is tried empty, as the wrong type, and left out.
     *
     * @param  array<string, list<string>>  $fields
     * @return array{payload: array<string, mixed>, probes: list<array{field: string, key: string, expect: string, payload: array<string, mixed>, says: string, rank: int}>, coverage: list<array{field: string, reason: string}>}|string
     */
    protected static function form(array $fields): array|string
    {
        $payload = [];
        $coverage = [];
        $plain = [];
        $lists = [];

        foreach ($fields as $field => $rules) {
            if (array_any(self::LEFT_OUT, fn (string $rule) => InputValues::rule($rules, $rule) !== null)) {
                continue;
            }

            // A list, or anything that holds other fields, is filled by them.
            if (array_any(array_keys($fields), fn (string $other) => str_starts_with($other, "{$field}.")) || InputValues::kind($rules) === 'array') {
                $lists[$field] = $rules;

                continue;
            }

            $value = InputValues::valid($rules, $field);

            if ($value === null) {
                if (self::required($rules)) {
                    return "cannot_fill:{$field}";
                }

                $coverage[] = ['field' => $field, 'reason' => 'cannot_fill'];

                continue;
            }

            Arr::set($payload, self::path($field), $value);
            $plain[$field] = $rules;
        }

        foreach ($lists as $field => $rules) {
            $path = self::path($field);
            $items = (int) (InputValues::rule($rules, 'size')[0] ?? InputValues::rule($rules, 'min')[0] ?? 1);

            if (! Arr::has($payload, $path)) {
                // A list with nothing inside it gets one plain item, unless
                // its rule names the keys an item must have.
                if (InputValues::rule($rules, 'array') !== []) {
                    if (self::required($rules)) {
                        return "cannot_fill:{$field}";
                    }

                    continue;
                }

                Arr::set($payload, $path, ['Probe']);
            }

            // Enough items for the list's own size rules, all like the first.
            if (array_is_list($list = (array) Arr::get($payload, $path)) && $items > 1) {
                Arr::set($payload, $path, array_fill(0, $items, $list[0] ?? 'Probe'));
            }
        }

        $payload = self::dates($payload, array_filter($plain, fn (string $field) => ! str_contains($field, '.'), ARRAY_FILTER_USE_KEY));

        foreach ($plain as $field => $rules) {
            if (InputValues::rule($rules, 'confirmed') !== null && ! str_contains($field, '.')) {
                $payload["{$field}_confirmation"] = $payload[$field];
            }
        }

        $probes = [];
        $with = function (string $path, mixed $value) use ($payload): array {
            Arr::set($payload, $path, $value);

            return $payload;
        };
        $without = function (string $path) use ($payload): array {
            Arr::forget($payload, $path);

            return $payload;
        };

        foreach ($lists as $field => $rules) {
            $path = self::path($field);

            if (! Arr::has($payload, $path)) {
                continue;
            }

            // "present" lets an empty list through; "required" does not.
            $limits = InputValues::rule($rules, 'required') !== null || (int) (InputValues::rule($rules, 'min')[0] ?? InputValues::rule($rules, 'size')[0] ?? 0) >= 1;
            $probes[] = ['field' => $path, 'key' => $field, 'expect' => self::required($rules) ? 'refuse' : 'accept', 'payload' => $without($path), 'says' => "{$field} left out", 'rank' => 0];
            $probes[] = ['field' => $path, 'key' => $field, 'expect' => $limits ? 'refuse' : 'accept', 'payload' => $with($path, []), 'says' => "{$field} as an empty list", 'rank' => 1];

            if (InputValues::rule($rules, 'array') !== null || InputValues::rule($rules, 'list') !== null) {
                $probes[] = ['field' => $path, 'key' => $field, 'expect' => 'refuse', 'payload' => $with($path, 'not-a-list'), 'says' => "{$field} as text instead of a list", 'rank' => 1];
            }
        }

        foreach ($plain as $field => $rules) {
            $path = self::path($field);
            $custom = array_any($rules, fn (string $rule) => $rule === 'closure' || str_starts_with($rule, 'custom:'));
            $conditional = in_array('conditional', $rules, true) || array_any(self::CONDITIONS, fn (string $rule) => InputValues::rule($rules, $rule) !== null);
            $change = fn (mixed $value, string $expect, string $says, int $rank) => ['field' => $path, 'key' => $field, 'expect' => $expect, 'payload' => $with($path, $value), 'says' => "{$field} {$says}", 'rank' => $rank];

            if ($custom) {
                $coverage[] = ['field' => $field, 'reason' => 'custom'];
            }

            if ($conditional) {
                $coverage[] = ['field' => $field, 'reason' => 'conditional'];

                // Only fields beside each other are linked, not inside lists.
                if (! str_contains($field, '.')) {
                    array_push($probes, ...self::conditions($field, $rules, $payload));
                }

                continue;
            }

            // A list item left out is the list made shorter: tried above.
            if (! str_ends_with($field, '*')) {
                $probes[] = ['field' => $path, 'key' => $field, 'expect' => self::required($rules) ? 'refuse' : 'accept', 'payload' => $without($path), 'says' => "{$field} left out", 'rank' => 0];
            }

            if ($custom) {
                continue;
            }

            if (($wrong = InputValues::wrongKind($rules)) !== null && InputValues::rule($rules, 'in') === null) {
                $probes[] = $change($wrong, 'refuse', 'as the wrong kind of value ('.json_encode($wrong).')', 1);
            }

            if (InputValues::rule($rules, 'in') !== null) {
                $probes[] = $change(InputValues::outsideChoices($rules), 'refuse', 'outside its choices', 1);
            }

            if (InputValues::kind($rules) === 'file' && ($file = InputValues::wrongFile($rules)) !== null) {
                $probes[] = $change($file, 'refuse', "as a file of the wrong type (.{$file['@file']})", 1);
            }

            if (InputValues::rule($rules, 'exists') !== null) {
                $probes[] = $change(InputValues::missingRow($rules), 'refuse', 'naming a row that does not exist', 1);
            }

            if (InputValues::rule($rules, 'confirmed') !== null && ! str_contains($field, '.')) {
                $probes[] = ['field' => $path, 'key' => $field, 'expect' => 'refuse', 'payload' => [...$payload, "{$field}_confirmation" => 'something-else'], 'says' => "{$field} not matching its confirmation", 'rank' => 1];
            }

            foreach (self::edges($path, $rules, $payload) as [$value, $expect, $says]) {
                $probes[] = $change($value, $expect, $says, $expect === 'refuse' ? 2 : 3);
            }
        }

        return ['payload' => $payload, 'probes' => $probes, 'coverage' => $coverage];
    }

    /**
     * Get where a field sits in the form: each "*" is the list's first item.
     */
    protected static function path(string $field): string
    {
        return str_replace('*', '0', $field);
    }

    /**
     * Each rule's edge on both sides: the last value it allows and the
     * first it does not.
     *
     * @param  list<string>  $rules
     * @param  array<string, mixed>  $payload
     * @return list<array{0: mixed, 1: string, 2: string}>
     */
    protected static function edges(string $field, array $rules, array $payload): array
    {
        $kind = InputValues::kind($rules);
        $edges = [];

        if (InputValues::rule($rules, 'in') !== null || InputValues::rule($rules, 'exists') !== null) {
            return [];
        }

        if ($kind === 'date') {
            return self::dateEdges($rules, $payload, Arr::get($payload, $field));
        }

        $bounds = [
            'max' => [null, 1],
            'min' => [-1, null],
            'size' => [-1, 1],
        ];
        $between = InputValues::rule($rules, 'between');
        $digits = InputValues::rule($rules, 'digits') ?? InputValues::rule($rules, 'digits_between');

        foreach ([...$bounds, 'between_low' => [-1, null], 'between_high' => [null, 1]] as $rule => [$below, $above]) {
            $limit = match ($rule) {
                'between_low' => $between[0] ?? null,
                'between_high' => $between[1] ?? null,
                default => InputValues::rule($rules, $rule)[0] ?? null,
            };

            if ($limit === null || ! is_numeric($limit) || $kind === 'digits') {
                continue;
            }

            $name = str_starts_with($rule, 'between') ? 'between:'.implode(',', (array) $between) : "{$rule}:{$limit}";

            foreach (array_filter([$below, $above]) as $step) {
                $edges[] = self::edge($field, $rules, $kind, (float) $limit + $step, 'refuse', $name);
            }

            $edges[] = self::edge($field, $rules, $kind, (float) $limit, 'accept', $name);
        }

        if ($digits !== null && $kind === 'digits') {
            $low = (int) $digits[0];
            $high = (int) ($digits[1] ?? $digits[0]);
            $name = isset($digits[1]) ? 'digits_between:'.implode(',', $digits) : "digits:{$low}";
            $edges[] = [str_repeat('1', $high + 1), 'refuse', 'with '.($high + 1)." digits ({$name})"];
            $edges[] = [str_repeat('1', $high), 'accept', "with {$high} digits ({$name})"];

            if ($low > 1) {
                $edges[] = [str_repeat('1', $low - 1), 'refuse', 'with '.($low - 1)." digits ({$name})"];
            }
        }

        return array_values(array_filter($edges));
    }

    /**
     * One edge value: a length for text, a number for numbers.
     *
     * @param  list<string>  $rules
     * @return array{0: mixed, 1: string, 2: string}|null
     */
    protected static function edge(string $field, array $rules, ?string $kind, float $at, string $expect, string $rule): ?array
    {
        // A file's size rules count kilobytes.
        if ($kind === 'file') {
            return $at < 1 || floor($at) !== $at ? null : [InputValues::file($rules, (int) $at), $expect, sprintf('of %d KB (%s)', $at, $rule)];
        }

        if (in_array($kind, ['integer', 'numeric'], true)) {
            if ($kind === 'integer' && floor($at) !== $at) {
                return null;
            }

            $value = floor($at) === $at ? (int) $at : $at;

            return [$value, $expect, "of {$value} ({$rule})"];
        }

        if (! in_array($kind, ['string', null], true) || $at < 1 || $at > 5000 || floor($at) !== $at) {
            return null;
        }

        $text = (string) InputValues::valid($rules, $field);
        $pad = InputValues::rule($rules, 'uppercase') !== null ? 'X' : 'x';
        $value = substr(str_pad($text, (int) $at, $pad), 0, (int) $at);

        return [$value, $expect, sprintf('%d characters long (%s)', $at, $rule)];
    }

    /**
     * Put each date on the side of the others its rules ask for: a date
     * after another field's comes a day after it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<string>>  $fields
     * @return array<string, mixed>
     */
    protected static function dates(array $payload, array $fields): array
    {
        foreach ([1, 2] as $pass) {
            foreach ($fields as $field => $rules) {
                if (! is_array($payload[$field] ?? null) || ! isset($payload[$field]['@date'])) {
                    continue;
                }

                foreach (['after' => 1, 'after_or_equal' => 1, 'before' => -1, 'before_or_equal' => -1] as $rule => $side) {
                    $other = InputValues::rule($rules, $rule)[0] ?? null;

                    if ($other === null) {
                        continue;
                    }

                    if (isset(self::DAYS[$other])) {
                        $payload[$field]['@date'] = self::DAYS[$other] + 30 * $side;
                    } elseif (is_array($payload[$other] ?? null) && isset($payload[$other]['@date'])) {
                        $payload[$field]['@date'] = $payload[$other]['@date'] + $side;
                    }
                }
            }
        }

        return $payload;
    }

    /**
     * The edges of a date's order rules: on the day each one names, and a
     * day to its wrong side.
     *
     * @param  list<string>  $rules
     * @param  array<string, mixed>  $payload
     * @param  array{'@date': int, format: string}  $date
     * @return list<array{0: mixed, 1: string, 2: string}>
     */
    protected static function dateEdges(array $rules, array $payload, array $date): array
    {
        $edges = [];

        foreach (['after' => [0, 1], 'after_or_equal' => [-1, 0], 'before' => [0, -1], 'before_or_equal' => [1, 0]] as $rule => [$wrong, $right]) {
            $other = InputValues::rule($rules, $rule)[0] ?? null;
            $day = match (true) {
                $other === null => null,
                isset(self::DAYS[$other]) => self::DAYS[$other],
                is_array($payload[$other] ?? null) && isset($payload[$other]['@date']) => $payload[$other]['@date'],
                default => null,
            };

            if ($day === null) {
                continue;
            }

            $edges[] = [['@date' => $day + $wrong, 'format' => $date['format']], 'refuse', "on the wrong side of {$other} ({$rule}:{$other})"];

            if (isset(self::DAYS[$other])) {
                $edges[] = [['@date' => $day + $right, 'format' => $date['format']], 'accept', "just inside {$rule}:{$other}"];
            }
        }

        return $edges;
    }

    /**
     * The probes for a field whose presence depends on others: make the
     * condition hold and leave the field out. This is where fields are
     * combined.
     *
     * @param  list<string>  $rules
     * @param  array<string, mixed>  $payload
     * @return list<array{field: string, key: string, expect: string, payload: array<string, mixed>, says: string, rank: int}>
     */
    protected static function conditions(string $field, array $rules, array $payload): array
    {
        $without = array_diff_key($payload, [$field => true]);
        $probes = [];

        if (($if = InputValues::rule($rules, 'required_if')) !== null && count($if) === 2 && array_key_exists($if[0], $payload)) {
            $probes[] = ['payload' => [...$without, $if[0] => $if[1]], 'says' => "{$field} left out while {$if[0]} is {$if[1]} (required_if)"];
        }

        foreach (['required_with', 'required_with_all'] as $rule) {
            $others = InputValues::rule($rules, $rule);

            if ($others !== null && array_diff($others, array_keys($payload)) === []) {
                $probes[] = ['payload' => $without, 'says' => "{$field} left out while ".implode(' and ', $others)." is given ({$rule})"];
            }
        }

        foreach (['required_without', 'required_without_all'] as $rule) {
            $others = InputValues::rule($rules, $rule);

            if ($others !== null) {
                $probes[] = ['payload' => array_diff_key($without, array_flip($others)), 'says' => "{$field} left out along with ".implode(' and ', $others)." ({$rule})"];
            }
        }

        return array_map(fn (array $probe) => ['field' => $field, 'key' => $field, 'expect' => 'refuse', ...$probe, 'rank' => 1], $probes);
    }

    /**
     * Determine if a field must always be sent.
     *
     * @param  list<string>  $rules
     */
    protected static function required(array $rules): bool
    {
        return InputValues::rule($rules, 'required') !== null || InputValues::rule($rules, 'present') !== null || InputValues::rule($rules, 'accepted') !== null;
    }

    /**
     * @param  Finding  $finding
     */
    protected static function finding(array $finding): string
    {
        return match ($finding['outcome']) {
            'accepted' => "{$finding['route']} accepted {$finding['says']}. It should be turned down with a message.",
            'refused' => "{$finding['route']} turned down {$finding['says']}, which its rules allow.",
            default => "{$finding['route']} broke (".($finding['exception'] ?? '500').") on {$finding['says']}. It should answer with a message.",
        };
    }

    /**
     * Say in words why a form or field was not fully tried.
     */
    protected static function reason(string $reason): string
    {
        [$code, $detail] = array_pad(explode(':', $reason, 2), 2, '');

        return match ($code) {
            'custom' => 'a rule written as code; tried only by leaving the field out',
            'conditional' => 'a rule that depends on other fields; tried only for when it asks for the field',
            'cannot_fill' => $detail === '' ? 'no value could be made for it' : "no value could be made for {$detail}",
            'no_user_factory' => 'the app has no user factory, so nobody could sign in',
            'needs_record' => "it needs a {$detail} that could not be added",
            'no_exists' => "it needs a row in {$detail} that could not be added",
            'no_rules' => 'it asked for no rules',
            'turned_away' => 'a signed-in person was turned away'.($detail === '' ? '' : " ({$detail})"),
            'form_refused' => "a filled-in form was turned down ({$detail})",
            'form_broke' => "a filled-in form broke ({$detail})",
            'other_field' => 'it was turned down for another field',
            default => 'the probe did not run',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function export(array $payload): string
    {
        return str_replace("\n", ' ', var_export($payload, true));
    }

    /**
     * Wrap generated test methods and helpers in a test class that writes
     * one line per call to the report.
     */
    protected static function testClass(string $class, string $methods, string $report, string $helpers): string
    {
        $report = var_export($report, true);

        return <<<PHP
<?php

namespace Tests\Feature;

use App\Models\User;
use BackedEnum;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Stringable;
use Tests\TestCase;
use Throwable;

class {$class} extends TestCase
{
    use RefreshDatabase;

{$methods}

{$helpers}

    /**
     * Send a form to a route as a new signed-in person, with a record for
     * each part of the address that names one.
     *
     * @param  array<string, mixed>  \$payload
     */
    private function send(string \$method, string \$uri, array \$payload): TestResponse
    {
        if (! method_exists(User::class, 'factory')) {
            throw new RuntimeException('no_user_factory');
        }

        \$this->actingAs(User::factory()->create());
        \$uri = preg_replace_callback('/\{(\w+)\??\}/', function (array \$part) {
            \$model = 'App\\\\Models\\\\'.Str::studly(\$part[1]);

            if (! class_exists(\$model) || ! method_exists(\$model, 'factory')) {
                throw new RuntimeException('needs_record:'.\$part[1]);
            }

            return (string) \$model::factory()->create()->getRouteKey();
        }, \$uri);

        return \$this->call(\$method, '/'.ltrim((string) \$uri, '/'), \$payload);
    }

    /**
     * Get the reason a probe could not run, without the app's own words.
     */
    private function reason(Throwable \$exception): string
    {
        return \$exception instanceof RuntimeException && preg_match('/^(no_user_factory|needs_record:\w+|no_exists:\w+)$/', \$exception->getMessage()) === 1
            ? \$exception->getMessage()
            : 'not_run';
    }

    /**
     * @param  array<string, mixed>  \$line
     */
    private function note(array \$line): void
    {
        file_put_contents(base_path({$report}), json_encode(\$line).PHP_EOL, FILE_APPEND);
        \$this->addToAssertionCount(1);
    }
}

PHP;
    }
}
