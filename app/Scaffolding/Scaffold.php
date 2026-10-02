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

            if (in_array("app/Models/{$record['name']}.php", $existing, true) || $this->hasMigration($existing, $table)) {
                continue;
            }

            $stamp = gmdate('Y_m_d_His', $at->getTimestamp() + $second++);

            $files["database/migrations/{$stamp}_create_{$table}_table.php"] = $this->migration($record);
            $files["app/Models/{$record['name']}.php"] = $this->model($record, $attributes);
            $files["database/factories/{$record['name']}Factory.php"] = $this->factory($record);
            $files["app/Http/Requests/Store{$record['name']}Request.php"] = $this->request($record);

            if (($record['access'] ?? null) !== null) {
                $files["app/Policies/{$record['name']}Policy.php"] = $this->policy($record, $record['access']);
                $files["tests/Feature/{$record['name']}AccessTest.php"] = $this->accessTest($record, $record['access']);
            }
        }

        return $files;
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
     * Write the form request. It asks the model's policy, so nobody may
     * create one until a policy says who may.
     *
     * @param  Record  $record
     */
    protected function request(array $record): string
    {
        $name = $record['name'];

        // The person who added a record is the signed-in user, never a
        // value the form sends: anyone could name someone else.
        $creator = in_array('creator', $record['access'] ?? [], true) ? self::creator($record['fields']) : null;
        $rules = array_map(
            fn (array $field) => var_export(FieldType::attribute($field), true).' => ['.implode(', ', array_map(fn (string $rule) => var_export($rule, true), FieldType::from($field['type'])->rules($field))).'],',
            array_values(array_filter($record['fields'], fn (array $field) => FieldType::attribute($field) !== $creator)),
        );

        return <<<PHP
            <?php

            namespace App\Http\Requests;

            use App\Models\\{$name};
            use Illuminate\Contracts\Validation\ValidationRule;
            use Illuminate\Foundation\Http\FormRequest;
            use Illuminate\Support\Facades\Gate;

            class Store{$name}Request extends FormRequest
            {
                /**
                 * Determine if the user is authorized to make this request.
                 */
                public function authorize(): bool
                {
                    return Gate::allows('create', {$name}::class);
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
