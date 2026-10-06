<?php

namespace Tests\Unit\Scaffolding;

use App\Scaffolding\Code;
use App\Scaffolding\FieldType;
use App\Scaffolding\Scaffold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The rules and casts a format writes into the app check and store what
 * people type as each format's examples say.
 */
class FormatSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The app's copies, loaded as the generated code would find them.
        foreach (['Rules/ValidIsbn.php', 'Rules/ValidPostalCode.php', 'Casts/Isbn.php', 'Casts/PostalCode.php', 'Casts/Money.php', 'Support/Countries.php'] as $file) {
            require_once resource_path("formats/{$file}");
        }
    }

    /**
     * Phone numbers are checked by a package the app installs, so they are
     * not run here.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function formats(): array
    {
        $field = fn (string $type, array $format = []) => [['name' => 'value', 'type' => $type, 'required' => true, 'choices' => [], 'of' => null, 'format' => $format]];

        return [
            'isbn of either length' => $field('isbn'),
            'isbn of 10' => $field('isbn', ['variants' => [10]]),
            'isbn of 13' => $field('isbn', ['variants' => [13]]),
            'postal code in Canada' => $field('postal_code', ['regions' => ['CA']]),
            'postal code in the US or UK' => $field('postal_code', ['regions' => ['US', 'GB']]),
            'postal code in Nigeria' => $field('postal_code', ['regions' => ['NG']]),
            'postal code anywhere' => $field('postal_code'),
            'web address' => $field('url'),
            'web address with http too' => $field('url', ['schemes' => ['http', 'https']]),
            'country' => $field('country'),
            'currency' => $field('currency'),
            'money in dollars' => $field('money', ['currency' => 'USD']),
            'money in yen' => $field('money', ['currency' => 'JPY']),
            'money in dinars' => $field('money', ['currency' => 'KWD']),
            'percentage' => $field('percentage'),
            'pattern' => $field('pattern', ['pattern' => '^[A-Z]{3}-\d{3}$', 'examples' => ['ABC-123', 'XYZ-999']]),
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     */
    #[DataProvider('formats')]
    public function test_each_format_accepts_refuses_and_stores_as_its_examples_say(array $field)
    {
        $type = FieldType::from($field['type']);
        $examples = $type->examples($field);
        $rules = array_map($this->evaluate(...), $type->rules($field));

        $this->assertNotSame([], $examples['valid']);

        foreach ($examples['valid'] as $value) {
            $this->assertTrue(Validator::make(['value' => $value], ['value' => $rules])->passes(), "accepts {$value}");
        }

        foreach ($examples['invalid'] as $value) {
            $this->assertTrue(Validator::make(['value' => $value], ['value' => $rules])->fails(), "refuses {$value}");
        }

        $cast = $type->cast($field);

        foreach ($examples['stored'] as [$typed, $stored]) {
            $this->assertSame($stored, (string) $this->store($cast, $typed), "stores {$typed}");
        }
    }

    public function test_an_amount_in_each_records_currency_is_stored_in_that_currency_and_read_back_as_typed()
    {
        $cast = FieldType::Money->cast(['name' => 'price', 'type' => 'money', 'required' => true, 'choices' => [], 'of' => null]);

        $this->assertSame(1250, $this->store($cast, '12.50', ['price_currency' => 'USD']));
        $this->assertSame(1250, $this->store($cast, '1250', ['price_currency' => 'JPY']));
        $this->assertSame(12505, $this->store($cast, '12.505', ['price_currency' => 'KWD']));
        $this->assertSame(1251, $this->store($cast, '12.505', ['price_currency' => 'USD']), 'rounded half up');
        $this->assertSame('12.50', $this->read($cast, 1250, ['price_currency' => 'USD']));
        $this->assertSame('1250', $this->read($cast, 1250, ['price_currency' => 'JPY']));
        $this->assertSame('0.05', $this->read($cast, 5, ['price_currency' => 'USD']));
    }

    public function test_the_files_a_format_uses_are_written_once_and_only_when_needed()
    {
        $records = [['name' => 'Book', 'fields' => [
            ['name' => 'isbn', 'type' => 'isbn', 'required' => true, 'choices' => [], 'of' => null],
            ['name' => 'price', 'type' => 'money', 'required' => true, 'choices' => [], 'of' => null],
        ], 'access' => null]];

        $support = (new Scaffold)->support($records, []);

        $this->assertSame(['app/Rules/ValidIsbn.php', 'app/Casts/Isbn.php', 'app/Casts/Money.php'], array_keys($support['files']));
        $this->assertSame(file_get_contents(resource_path('formats/Casts/Money.php')), $support['files']['app/Casts/Money.php']);
        $this->assertSame([], $support['notes']);

        // A record with no format needs none, and a record the app has is
        // left to the coding agent.
        $this->assertSame(['files' => [], 'notes' => []], (new Scaffold)->support([['name' => 'Note', 'fields' => [['name' => 'body', 'type' => 'text', 'required' => true, 'choices' => [], 'of' => null]], 'access' => null]], []));
        $this->assertSame(['files' => [], 'notes' => []], (new Scaffold)->support($records, ['app/Models/Book.php']));
    }

    public function test_the_apps_own_file_of_the_same_name_is_kept_and_noted()
    {
        $records = [['name' => 'Book', 'fields' => [['name' => 'isbn', 'type' => 'isbn', 'required' => true, 'choices' => [], 'of' => null]], 'access' => null]];
        $existing = ['app/Rules/ValidIsbn.php', 'app/Casts/Isbn.php'];

        $support = (new Scaffold)->support($records, $existing, [
            'app/Rules/ValidIsbn.php' => "<?php\n\n// The app's own check.\n",
            'app/Casts/Isbn.php' => (string) file_get_contents(resource_path('formats/Casts/Isbn.php')),
        ]);

        $this->assertSame([], $support['files']);
        $this->assertSame(['app/Rules/ValidIsbn.php: the app already has its own file here, so ours was not written. The generated rules and casts use it; check it does what they expect.'], $support['notes']);
    }

    /**
     * Run a rule as the app would: a string as it is, code as written with
     * its imports.
     */
    protected function evaluate(string|Code $rule): mixed
    {
        if (is_string($rule)) {
            return $rule;
        }

        $imports = implode('', array_map(fn (string $class) => "use {$class};", $rule->imports));

        return eval("{$imports} return {$rule->expression};");
    }

    /**
     * Store a value through the field's cast, as the model would.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function store(string|Code|null $cast, string $value, array $attributes = []): mixed
    {
        return $this->cast($cast)?->set(new class extends Model {}, 'value', $value, $attributes) ?? $this->builtIn($cast, $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function read(Code $cast, int $value, array $attributes): mixed
    {
        return $this->cast($cast)?->get(new class extends Model {}, 'value', $value, $attributes);
    }

    protected function cast(string|Code|null $cast): ?object
    {
        if (! $cast instanceof Code) {
            return null;
        }

        [$class, $parameters] = array_pad(explode(':', (string) $this->evaluate($cast), 2), 2, null);

        return new $class(...($parameters === null ? [] : explode(',', $parameters)));
    }

    /**
     * A value with no cast class: Laravel's own cast, or none.
     */
    protected function builtIn(string|Code|null $cast, string $value): string
    {
        return is_string($cast) && str_starts_with($cast, 'decimal:') ? number_format((float) $value, (int) substr($cast, 8), '.', '') : $value;
    }
}
