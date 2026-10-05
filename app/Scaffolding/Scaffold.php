<?php

namespace App\Scaffolding;

use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Write the parts a data shape fixes, with no model (§9, delegation by
 * certainty): for each new record, its migration, model, factory and form
 * request, and when the shape says who may do what, its policy and the
 * tests that guard it. Every name and rule comes from the one shape, so they cannot
 * drift apart. The coding agent builds on the result as ordinary code.
 *
 * Each new record also gets the controller actions that change it and their
 * routes (routes()), since a route that skips the policy is where the wrong
 * person changes a record. The screens are left to the coding agent: they
 * depend on how the app draws its own.
 *
 * Only new records are written. A record whose model the app already has is
 * left to the coding agent, since changing it means reading what is there.
 *
 * @phpstan-type Field array{name: string, type: string, required: bool, choices: list<string>, of: string|null, label?: string}
 * @phpstan-type Access array{view: string, create: string, update: string, delete: string}
 * @phpstan-type Record array{name: string, fields: list<Field>, access?: Access|null, label?: string}
 */
class Scaffold
{
    /**
     * What a person may do with a record.
     */
    public const ACTIONS = ['view', 'create', 'update', 'delete'];

    /**
     * Who may do it: anyone, anyone signed in, or only the person who made
     * the record.
     */
    public const WHO = ['everyone', 'signed_in', 'creator'];

    /**
     * Get the files for the shape's new records, by path.
     *
     * @param  list<Record>  $records
     * @param  list<string>  $existing  The paths the app has now
     * @param  bool  $attributes  Whether the app declares fillable fields with the #[Fillable] attribute
     * @return array<string, string>
     */
    public function files(array $records, array $existing, DateTimeInterface $at, bool $attributes = false): array
    {
        $files = [];
        $second = 0;

        foreach ($this->inOrder($records) as $record) {
            $table = FieldType::table($record['name']);

            if (! $this->isNew($record, $existing)) {
                continue;
            }

            $stamp = gmdate('Y_m_d_His', $at->getTimestamp() + $second++);

            $files["database/migrations/{$stamp}_create_{$table}_table.php"] = $this->migration($record);
            $files["app/Models/{$record['name']}.php"] = $this->model($record, $attributes);
            $files["database/factories/{$record['name']}Factory.php"] = $this->factory($record);
            $files["app/Http/Requests/Store{$record['name']}Request.php"] = $this->request($record, 'create');

            if (($record['access'] ?? null) !== null) {
                $files["app/Policies/{$record['name']}Policy.php"] = $this->policy($record, $record['access']);
                $files["tests/Feature/{$record['name']}AccessTest.php"] = $this->accessTest($record, $record['access']);
            }
        }

        return $files;
    }

    /**
     * Get the controller, update request and routes that let people change
     * each new record, and notes on what was left to the coding agent and
     * why. A route the app already has is never written over: that record
     * gets neither a controller nor routes.
     *
     * @param  list<Record>  $records
     * @param  list<string>  $existing  The paths the app has now
     * @param  array<string, string>  $routes  The app's route files, by path
     * @return array{files: array<string, string>, notes: list<string>}
     */
    public function routes(array $records, array $existing, array $routes): array
    {
        $files = [];
        $notes = [];
        $resources = [];

        foreach ($this->inOrder($records) as $record) {
            if (! $this->isNew($record, $existing)) {
                continue;
            }

            $name = $record['name'];
            $uri = RouteFile::uri($name);
            $controller = "app/Http/Controllers/{$name}Controller.php";
            $request = "app/Http/Requests/Update{$name}Request.php";

            if (($found = array_values(array_intersect([$controller, $request], $existing))) !== []) {
                $notes[] = "{$name}: the app already has {$found[0]}, so no controller or routes were written for it.";

                continue;
            }

            if (($taken = RouteFile::taken($routes, $uri)) !== null) {
                $notes[] = "{$name}: the app already has a route {$taken}, so no controller or routes were written for it. Add its actions beside that route.";

                continue;
            }

            $files[$controller] = $this->controller($record, in_array('app/Http/Controllers/Controller.php', $existing, true));
            $files[$request] = $this->request($record, 'update');
            $access = $record['access'] ?? null;
            $resources[] = [
                'name' => $name,
                'uri' => $uri,
                // Without access in the shape the policy is still to be
                // written, so the routes sit with the signed-in ones.
                'signed_in' => $access === null || array_diff([$access['create'], $access['update'], $access['delete']], ['everyone']) !== [],
            ];
        }

        if ($resources !== []) {
            $web = isset($routes['routes/web.php']) ? RouteFile::add($routes['routes/web.php'], $resources) : null;

            if ($web !== null) {
                $files['routes/web.php'] = $web;
            } else {
                $names = implode(', ', array_column($resources, 'name'));
                $notes[] = isset($routes['routes/web.php'])
                    ? "{$names}: routes/web.php has no plain group of routes for signed-in people (Route::middleware('auth')->group(function () { … })), so their routes were not added. Add them where the app keeps routes for signed-in people."
                    : "{$names}: the app has no routes/web.php, so their routes were not added. Add them where the app keeps its routes.";
            }
        }

        return ['files' => $files, 'notes' => $notes];
    }

    /**
     * Determine if the record is new to the app: neither its model nor a
     * migration that creates its table exists.
     *
     * @param  Record  $record
     * @param  list<string>  $existing
     */
    protected function isNew(array $record, array $existing): bool
    {
        return ! in_array("app/Models/{$record['name']}.php", $existing, true)
            && ! $this->hasMigration($existing, FieldType::table($record['name']));
    }

    /**
     * Put each record after the new records it links to, so its migration
     * runs once their tables exist.
     *
     * @param  list<Record>  $records
     * @return list<Record>
     */
    protected function inOrder(array $records): array
    {
        $names = array_column($records, 'name');
        $ordered = [];
        $placed = [];

        while (count($ordered) < count($records)) {
            $before = count($ordered);

            foreach ($records as $record) {
                $needs = array_filter(array_map(
                    fn (array $field) => $field['type'] === FieldType::BelongsTo->value ? $field['of'] : null,
                    $record['fields'],
                ), fn (?string $of) => $of !== null && $of !== $record['name'] && in_array($of, $names, true));

                if (! isset($placed[$record['name']]) && array_diff($needs, array_keys($placed)) === []) {
                    $ordered[] = $record;
                    $placed[$record['name']] = true;
                }
            }

            // Records that link to each other cannot all go first; keep the
            // planner's order for the rest.
            if (count($ordered) === $before) {
                foreach ($records as $record) {
                    if (! isset($placed[$record['name']])) {
                        $ordered[] = $record;
                        $placed[$record['name']] = true;
                    }
                }
            }
        }

        return $ordered;
    }

    /**
     * Determine if the app already has a migration that creates the table.
     *
     * @param  list<string>  $existing
     */
    protected function hasMigration(array $existing, string $table): bool
    {
        foreach ($existing as $path) {
            if (str_starts_with($path, 'database/migrations/') && str_ends_with($path, "_create_{$table}_table.php")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Record  $record
     */
    protected function migration(array $record): string
    {
        $table = var_export(FieldType::table($record['name']), true);
        $columns = array_map(fn (array $field) => FieldType::from($field['type'])->column($field), $record['fields']);

        return <<<PHP
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                /**
                 * Run the migrations.
                 */
                public function up(): void
                {
                    Schema::create({$table}, function (Blueprint \$table) {
                        \$table->id();
            {$this->lines($columns, 12)}
                        \$table->timestamps();
                    });
                }

                /**
                 * Reverse the migrations.
                 */
                public function down(): void
                {
                    Schema::dropIfExists({$table});
                }
            };

            PHP;
    }

    /**
     * @param  Record  $record
     */
    protected function model(array $record, bool $attributes): string
    {
        $name = $record['name'];
        $fillable = '['.implode(', ', array_map(fn (array $field) => var_export(FieldType::attribute($field), true), $record['fields'])).']';
        $imports = ["Database\\Factories\\{$name}Factory", 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory', 'Illuminate\\Database\\Eloquent\\Model'];
        $body = [];

        if ($attributes) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\Attributes\\Fillable';
        } else {
            $body[] = <<<PHP
                    /**
                     * The attributes that are mass assignable.
                     *
                     * @var list<string>
                     */
                    protected \$fillable = {$fillable};
                PHP;
        }

        $casts = [];

        foreach ($record['fields'] as $field) {
            if (($cast = FieldType::from($field['type'])->cast()) !== null) {
                $casts[] = var_export($field['name'], true).' => '.var_export($cast, true).',';
            }
        }

        if ($casts !== []) {
            $body[] = <<<PHP
                    /**
                     * Get the attributes that should be cast.
                     *
                     * @return array<string, string>
                     */
                    protected function casts(): array
                    {
                        return [
                {$this->lines($casts, 12)}
                        ];
                    }
                PHP;
        }

        foreach ($record['fields'] as $field) {
            if ($field['type'] !== FieldType::BelongsTo->value) {
                continue;
            }

            $imports[] = 'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo';
            $method = Str::camel($field['name']);
            $body[] = <<<PHP
                    /**
                     * Get the {$this->words($field['name'])} this {$this->words($name)} belongs to.
                     *
                     * @return BelongsTo<{$field['of']}, \$this>
                     */
                    public function {$method}(): BelongsTo
                    {
                        return \$this->belongsTo({$field['of']}::class, '{$field['name']}_id');
                    }
                PHP;
        }

        $attribute = $attributes ? "#[Fillable({$fillable})]\n" : '';
        $use = $this->imports($imports);
        $members = $body === [] ? '' : "\n".implode("\n\n", $body)."\n";

        return <<<PHP
            <?php

            namespace App\Models;

            {$use}

            {$attribute}class {$name} extends Model
            {
                /** @use HasFactory<{$name}Factory> */
                use HasFactory;
            {$members}}

            PHP;
    }

    /**
     * @param  Record  $record
     */
    protected function factory(array $record): string
    {
        $name = $record['name'];
        $imports = ["App\\Models\\{$name}", 'Illuminate\\Database\\Eloquent\\Factories\\Factory'];
        $values = [];

        foreach ($record['fields'] as $field) {
            if ($field['type'] === FieldType::BelongsTo->value) {
                $imports[] = "App\\Models\\{$field['of']}";
            }

            $values[] = var_export(FieldType::attribute($field), true).' => '.FieldType::from($field['type'])->fake($field).',';
        }

        $use = $this->imports($imports);

        return <<<PHP
            <?php

            namespace Database\Factories;

            {$use}

            /**
             * @extends Factory<{$name}>
             */
            class {$name}Factory extends Factory
            {
                /**
                 * Define the model's default state.
                 *
                 * @return array<string, mixed>
                 */
                public function definition(): array
                {
                    return [
            {$this->lines($values, 12)}
                    ];
                }
            }

            PHP;
    }

    /**
     * Write the form request for adding or changing a record. It asks the
     * model's policy, so nobody may do either until a policy says who may.
     *
     * @param  Record  $record
     * @param  'create'|'update'  $action
     */
    protected function request(array $record, string $action): string
    {
        $name = $record['name'];
        $class = ($action === 'create' ? 'Store' : 'Update')."{$name}Request";
        $subject = $action === 'create' ? "{$name}::class" : '$this->route('.var_export(str_replace('-', '_', Str::singular(RouteFile::uri($name))), true).')';

        // The person who added a record is the signed-in user, never a
        // value the form sends: anyone could name someone else.
        $creator = in_array('creator', $record['access'] ?? [], true) ? self::creator($record['fields']) : null;
        $rules = array_map(
            fn (array $field) => var_export(FieldType::attribute($field), true).' => ['.implode(', ', array_map(fn (string $rule) => var_export($rule, true), FieldType::from($field['type'])->rules($field))).'],',
            array_values(array_filter($record['fields'], fn (array $field) => FieldType::attribute($field) !== $creator)),
        );
        $use = $this->imports([
            ...($action === 'create' ? ["App\\Models\\{$name}"] : []),
            'Illuminate\\Contracts\\Validation\\ValidationRule',
            'Illuminate\\Foundation\\Http\\FormRequest',
            'Illuminate\\Support\\Facades\\Gate',
        ]);

        return <<<PHP
            <?php

            namespace App\Http\Requests;

            {$use}

            class {$class} extends FormRequest
            {
                /**
                 * Determine if the user is authorized to make this request.
                 */
                public function authorize(): bool
                {
                    return Gate::allows('{$action}', {$subject});
                }

                /**
                 * Get the validation rules that apply to the request.
                 *
                 * @return array<string, ValidationRule|array<mixed>|string>
                 */
                public function rules(): array
                {
                    return [
            {$this->lines($rules, 12)}
                    ];
                }
            }

            PHP;
    }

    /**
     * Write the controller actions that change a record. Each one asks the
     * policy, through its form request or the gate, before it changes
     * anything. Each sends the person back to where they were, which suits
     * whatever the app draws its screens with.
     *
     * @param  Record  $record
     */
    protected function controller(array $record, bool $base): string
    {
        $name = $record['name'];
        $variable = Str::camel($name);
        $words = $this->words($name);
        $creator = in_array('creator', $record['access'] ?? [], true) ? self::creator($record['fields']) : null;

        // The person who added it is the signed-in user, never the form.
        $values = $creator === null ? '$request->validated()' : "[...\$request->validated(), '{$creator}' => \$request->user()?->id]";
        $use = $this->imports([
            "App\\Http\\Requests\\Store{$name}Request",
            "App\\Http\\Requests\\Update{$name}Request",
            "App\\Models\\{$name}",
            'Illuminate\\Http\\RedirectResponse',
            'Illuminate\\Support\\Facades\\Gate',
        ]);
        $extends = $base ? ' extends Controller' : '';

        return <<<PHP
            <?php

            namespace App\Http\Controllers;

            {$use}

            class {$name}Controller{$extends}
            {
                /**
                 * Store a new {$words}.
                 */
                public function store(Store{$name}Request \$request): RedirectResponse
                {
                    {$name}::create({$values});

                    return back();
                }

                /**
                 * Update the {$words}.
                 */
                public function update(Update{$name}Request \$request, {$name} \${$variable}): RedirectResponse
                {
                    \${$variable}->update(\$request->validated());

                    return back();
                }

                /**
                 * Remove the {$words}.
                 */
                public function destroy({$name} \${$variable}): RedirectResponse
                {
                    Gate::authorize('delete', \${$variable});

                    \${$variable}->delete();

                    return back();
                }
            }

            PHP;
    }

    /**
     * Get the field that says who made the record: its first link to a user.
     *
     * @param  list<Field>  $fields
     */
    public static function creator(array $fields): ?string
    {
        foreach ($fields as $field) {
            if ($field['type'] === FieldType::BelongsTo->value && $field['of'] === 'User') {
                return $field['name'].'_id';
            }
        }

        return null;
    }

    /**
     * Write the policy from who may do what. Laravel finds it by its name.
     *
     * @param  Record  $record
     * @param  Access  $access
     */
    protected function policy(array $record, array $access): string
    {
        $name = $record['name'];
        $variable = Str::camel($name);
        $creator = self::creator($record['fields']);
        $methods = [];

        foreach (['viewAny' => 'view', 'view' => 'view', 'create' => 'create', 'update' => 'update', 'delete' => 'delete'] as $method => $action) {
            $who = $access[$action];
            $one = ! in_array($method, ['viewAny', 'create'], true);
            $user = $who === 'everyone' ? '?User $user' : 'User $user';
            $parameters = $one ? "{$user}, {$name} \${$variable}" : $user;

            // A list or a new record has no creator yet: anyone signed in.
            $answer = match (true) {
                $who === 'everyone', $who === 'signed_in', ! $one => 'true',
                default => "\${$variable}->{$this->relation($creator)}()->is(\$user)",
            };

            // Only the creator may see each one, so a list holds only theirs.
            $note = $method === 'viewAny' && $who === 'creator' ? "\n     *\n     * The list holds only the {$this->words($name)} records the user added." : '';

            $methods[] = <<<PHP
                    /**
                     * Determine whether the user can {$this->ability($method, $name)}.{$note}
                     */
                    public function {$method}({$parameters}): bool
                    {
                        return {$answer};
                    }
                PHP;
        }

        $body = implode("\n\n", $methods);

        return <<<PHP
            <?php

            namespace App\Policies;

            use App\Models\\{$name};
            use App\Models\User;

            class {$name}Policy
            {
            {$body}
            }

            PHP;
    }

    /**
     * Write the tests that guard who may do what, through the policy.
     *
     * @param  Record  $record
     * @param  Access  $access
     */
    protected function accessTest(array $record, array $access): string
    {
        $name = $record['name'];
        $words = $this->words($name);
        $plural = Str::plural($words);
        $creator = self::creator($record['fields']);
        $tests = [];

        foreach (self::ACTIONS as $action) {
            $ability = $action === 'view' ? 'view' : $action;
            $who = $access[$action];
            $subject = $action === 'create' ? "{$name}::class" : '$record';
            $verb = ['view' => 'see', 'create' => 'add', 'update' => 'change', 'delete' => 'remove'][$action];
            $object = $action === 'create' ? $plural : "a {$words}";

            if ($who === 'everyone') {
                $tests[] = $this->test("test_anyone_can_{$verb}_{$this->snake($object)}", [
                    ...($action === 'create' ? [] : ["\$record = {$name}::factory()->create();"]),
                    "\$this->assertTrue(Gate::forUser(null)->allows('{$ability}', {$subject}));",
                ]);

                continue;
            }

            $tests[] = $this->test("test_a_guest_cannot_{$verb}_{$this->snake($object)}", [
                ...($action === 'create' ? [] : ["\$record = {$name}::factory()->create();"]),
                "\$this->assertFalse(Gate::forUser(null)->allows('{$ability}', {$subject}));",
            ]);

            if ($who === 'creator' && $action !== 'create') {
                $tests[] = $this->test("test_only_the_person_who_added_{$this->snake("a {$words}")}_can_{$verb}_it", [
                    "\$record = {$name}::factory()->create();",
                    "\$this->assertTrue(Gate::forUser(\$record->{$this->relation($creator)})->allows('{$ability}', \$record));",
                    "\$this->assertFalse(Gate::forUser(User::factory()->create())->allows('{$ability}', \$record));",
                ]);
            } else {
                $tests[] = $this->test("test_anyone_signed_in_can_{$verb}_{$this->snake($object)}", [
                    ...($action === 'create' ? [] : ["\$record = {$name}::factory()->create();"]),
                    "\$this->assertTrue(Gate::forUser(User::factory()->create())->allows('{$ability}', {$subject}));",
                ]);
            }
        }

        $body = implode("\n\n", $tests);

        return <<<PHP
            <?php

            namespace Tests\Feature;

            use App\Models\\{$name};
            use App\Models\User;
            use Illuminate\Foundation\Testing\RefreshDatabase;
            use Illuminate\Support\Facades\Gate;
            use Tests\TestCase;

            class {$name}AccessTest extends TestCase
            {
                use RefreshDatabase;

            {$body}
            }

            PHP;
    }

    /**
     * @param  list<string>  $lines
     */
    protected function test(string $name, array $lines): string
    {
        return "    public function {$name}(): void\n    {\n".$this->lines($lines, 8)."\n    }";
    }

    protected function ability(string $method, string $name): string
    {
        $words = $this->words($name);

        return match ($method) {
            'viewAny' => 'see the list of '.Str::plural($words),
            'view' => "see the {$words}",
            'create' => "add a {$words}",
            'update' => "change the {$words}",
            default => "remove the {$words}",
        };
    }

    /**
     * Get the relation a creator column belongs to, such as user for user_id.
     */
    protected function relation(?string $column): string
    {
        return Str::camel(Str::beforeLast((string) $column, '_id'));
    }

    protected function snake(string $words): string
    {
        return str_replace(' ', '_', $words);
    }

    /**
     * Join lines, each indented by the given number of spaces.
     *
     * @param  list<string>  $lines
     */
    protected function lines(array $lines, int $spaces): string
    {
        return implode("\n", array_map(fn (string $line) => str_repeat(' ', $spaces).$line, $lines));
    }

    /**
     * @param  list<string>  $classes
     */
    protected function imports(array $classes): string
    {
        $classes = array_values(array_unique($classes));
        sort($classes);

        return implode("\n", array_map(fn (string $class) => "use {$class};", $classes));
    }

    protected function words(string $name): string
    {
        return str_replace('_', ' ', Str::snake($name));
    }
}
