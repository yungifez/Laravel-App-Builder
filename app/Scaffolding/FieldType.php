<?php

namespace App\Scaffolding;

use Illuminate\Support\Str;

/**
 * The kinds of field a data shape may hold, and the fixed table that maps
 * each one to its column, validation rules, cast and factory value (§9,
 * delegation by certainty). No model is asked: the same field always gives
 * the same code.
 *
 * A format (§9, "Formats: decided once, then generated") is a kind of field
 * with a few settings, in the field's `format` map: the regions of a phone
 * number or postal code, the schemes of a web address, the lengths of an
 * ISBN, the currency of an amount, or a pattern and its examples. Each
 * has fixed examples of what it accepts, refuses and stores.
 *
 * @phpstan-type Field array{name: string, type: string, required: bool, choices: list<string>, of: string|null, format?: array<string, mixed>}
 * @phpstan-type Examples array{valid: list<string>, invalid: list<string>, stored: list<array{string, string}>}
 */
enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Email = 'email';

    /** One of a few fixed values, kept as a string. */
    case Choice = 'choice';

    /** A link to a record of another model. */
    case BelongsTo = 'belongs_to';

    /** A phone number, stored as E.164 ("+12505550123"). */
    case Phone = 'phone';

    /** A postal code, stored uppercase with one inner space. */
    case PostalCode = 'postal_code';

    /** A web address, stored as typed. */
    case Url = 'url';

    /** An ISBN, stored as its digits and X. */
    case Isbn = 'isbn';

    /** A country, stored as its ISO 3166 code. */
    case Country = 'country';

    /** An amount of money, stored as whole minor units. */
    case Money = 'money';

    /** A share from 0 to 100. */
    case Percentage = 'percentage';

    /** Text of a shape no other kind covers, checked by a pattern. */
    case Pattern = 'pattern';

    /**
     * The currency of an amount whose currency is chosen per record. It is
     * written beside the amount, never planned on its own.
     */
    case Currency = 'currency';

    /**
     * The regions whose postal codes and phone numbers we know. Any other
     * region is built as "any".
     */
    public const REGIONS = ['CA', 'US', 'GB', 'NG'];

    /**
     * Currencies whose minor unit is not a hundredth; the same table as the
     * Money cast written into the app.
     */
    public const MINOR_UNITS = ['JPY' => 0, 'KRW' => 0, 'BHD' => 3, 'KWD' => 3, 'OMR' => 3];

    /**
     * The kinds a plan may choose: all but a currency, which is written
     * beside its amount.
     *
     * @return list<self>
     */
    public static function planned(): array
    {
        return array_filter(self::cases(), fn (self $type) => $type !== self::Currency);
    }

    /**
     * Keep only the format settings the kind of field uses, as given. A
     * setting left out stays out, so what the notes say can fill it in
     * before the defaults do.
     *
     * @param  array<string, mixed>  $format
     * @return array<string, mixed>
     */
    public function format(array $format): array
    {
        $kept = match ($this) {
            self::Phone, self::PostalCode => ['regions' => self::strings($format['regions'] ?? []) === [] ? [] : self::regions($format['regions'])],
            self::Url => ['schemes' => array_values(array_intersect(['http', 'https'], self::strings($format['schemes'] ?? [])))],
            self::Isbn => ['variants' => array_values(array_intersect([10, 13], array_map(intval(...), self::strings($format['variants'] ?? []))))],
            self::Money => ['currency' => is_string($format['currency'] ?? null) && preg_match('/^([A-Z]{3}|per_record)$/', $format['currency']) === 1 ? $format['currency'] : ''],
            self::Pattern => ['pattern' => is_string($format['pattern'] ?? null) ? $format['pattern'] : '', 'examples' => self::strings($format['examples'] ?? [])],
            default => [],
        };

        return array_filter($kept, fn (mixed $value) => $value !== [] && $value !== '');
    }

    /**
     * Get the attribute the field is stored in.
     *
     * @param  Field  $field
     */
    public static function attribute(array $field): string
    {
        return self::from($field['type']) === self::BelongsTo ? $field['name'].'_id' : $field['name'];
    }

    /**
     * Get the fields a record stores for the given ones: an amount whose
     * currency is chosen per record gets its currency first, so the amount
     * is read in it.
     *
     * @param  list<Field>  $fields
     * @return list<Field>
     */
    public static function expand(array $fields): array
    {
        $expanded = [];

        foreach ($fields as $field) {
            if (self::from($field['type']) === self::Money && self::settings($field)['currency'] === 'per_record') {
                $expanded[] = ['name' => self::currencyColumn($field), 'type' => self::Currency->value, 'required' => $field['required'], 'choices' => [], 'of' => null];
            }

            $expanded[] = $field;
        }

        return $expanded;
    }

    /**
     * Get the field's format settings, each filled in when the shape left it
     * out or gave one we do not know.
     *
     * @param  Field  $field
     * @return array<string, mixed>
     */
    public static function settings(array $field): array
    {
        $format = $field['format'] ?? [];

        return match (self::from($field['type'])) {
            self::Phone, self::PostalCode => ['regions' => self::regions($format['regions'] ?? [])],
            self::Url => ['schemes' => array_values(array_intersect(['http', 'https'], self::strings($format['schemes'] ?? []))) ?: ['https']],
            self::Isbn => ['variants' => array_values(array_intersect([10, 13], array_map(intval(...), self::strings($format['variants'] ?? [])))) ?: [10, 13]],
            self::Money => ['currency' => is_string($format['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', $format['currency']) === 1 ? $format['currency'] : 'per_record'],
            self::Pattern => ['pattern' => is_string($format['pattern'] ?? null) ? $format['pattern'] : '', 'examples' => self::strings($format['examples'] ?? [])],
            default => [],
        };
    }

    /**
     * Determine if a pattern field's examples all pass its pattern. One that
     * refuses its own examples is broken, and is not built.
     *
     * @param  Field  $field
     */
    public static function patternHolds(array $field): bool
    {
        $settings = self::settings($field);

        if ($settings['pattern'] === '' || count($settings['examples']) < 2 || @preg_match(self::delimited($settings['pattern']), '') === false) {
            return false;
        }

        foreach ($settings['examples'] as $example) {
            if (preg_match(self::delimited($settings['pattern']), $example) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the migration line that adds the field's column.
     *
     * @param  Field  $field
     */
    public function column(array $field): string
    {
        $name = var_export(self::attribute($field), true);
        $nullable = $field['required'] ? '' : '->nullable()';

        return match ($this) {
            self::String, self::Email, self::Choice, self::Pattern => "\$table->string({$name}){$nullable};",
            self::Text => "\$table->text({$name}){$nullable};",
            self::Integer => "\$table->integer({$name}){$nullable};",
            self::Decimal => "\$table->decimal({$name}, 10, 2){$nullable};",
            // A yes or no is never unknown: it starts as no.
            self::Boolean => "\$table->boolean({$name})->default(false);",
            self::Date => "\$table->date({$name}){$nullable};",
            self::DateTime => "\$table->dateTime({$name}){$nullable};",
            self::BelongsTo => $field['required']
                ? "\$table->foreignId({$name})->constrained(".var_export(self::table((string) $field['of']), true).')->cascadeOnDelete();'
                : "\$table->foreignId({$name})->nullable()->constrained(".var_export(self::table((string) $field['of']), true).')->nullOnDelete();',
            self::Phone => "\$table->string({$name}, 32){$nullable};",
            self::PostalCode => "\$table->string({$name}, 16){$nullable};",
            self::Url => "\$table->string({$name}, 2048){$nullable};",
            self::Isbn => "\$table->string({$name}, 13){$nullable};",
            self::Country => "\$table->char({$name}, 2){$nullable};",
            self::Currency => "\$table->char({$name}, 3){$nullable};",
            self::Money => "\$table->unsignedBigInteger({$name}){$nullable};",
            self::Percentage => "\$table->decimal({$name}, 5, 2){$nullable};",
        };
    }

    /**
     * Get the validation rules for the field: rule strings, and rule objects
     * as code. What people type is checked loosely (spaces, hyphens and
     * case); the cast stores it one way.
     *
     * @param  Field  $field
     * @return list<string|Code>
     */
    public function rules(array $field): array
    {
        $presence = $this === self::Boolean ? 'sometimes' : ($field['required'] ? 'required' : 'nullable');
        $settings = self::settings($field);

        return [$presence, ...match ($this) {
            self::String => ['string', 'max:255'],
            self::Text => ['string', 'max:65535'],
            self::Integer => ['integer'],
            self::Decimal => ['numeric'],
            self::Boolean => ['boolean'],
            self::Date, self::DateTime => ['date'],
            self::Email => ['email', 'max:255'],
            self::Choice => ['in:'.implode(',', $field['choices'])],
            self::BelongsTo => ['exists:'.self::table((string) $field['of']).',id'],
            self::Phone => ['string', 'phone:'.implode(',', array_map(fn (string $region) => $region === 'any' ? 'INTERNATIONAL' : $region, $settings['regions']))],
            self::PostalCode => ['string', new Code('new ValidPostalCode('.self::list($settings['regions']).')', ['App\\Rules\\ValidPostalCode'])],
            self::Url => ['string', 'max:2048', 'url:'.implode(',', $settings['schemes'])],
            self::Isbn => ['string', new Code('new ValidIsbn('.self::list($settings['variants']).')', ['App\\Rules\\ValidIsbn'])],
            self::Country => ['string', new Code('Rule::in(Countries::CODES)', ['App\\Support\\Countries', 'Illuminate\\Validation\\Rule'])],
            self::Currency => ['string', 'regex:/^[A-Z]{3}$/'],
            // An amount is typed as people write it ("12.50"), never in
            // cents; a currency per record allows its widest, three places.
            self::Money => ['numeric', 'min:0', 'decimal:0,'.($settings['currency'] === 'per_record' ? 3 : self::places($settings['currency']))],
            self::Percentage => ['numeric', 'between:0,100'],
            self::Pattern => ['string', 'max:255', 'regex:'.self::delimited($settings['pattern'])],
        }];
    }

    /**
     * Get the cast for the field, if it needs one: a cast name, or a cast
     * class as code.
     *
     * @param  Field  $field
     */
    public function cast(array $field): string|Code|null
    {
        $settings = self::settings($field);

        return match ($this) {
            self::Integer => 'integer',
            self::Decimal, self::Percentage => 'decimal:2',
            self::Boolean => 'boolean',
            self::Date => 'date',
            self::DateTime => 'datetime',
            self::Phone => new Code('E164PhoneNumberCast::class'.(($regions = array_diff($settings['regions'], ['any'])) === [] ? '' : '.'.var_export(':'.implode(',', $regions), true)), ['Propaganistas\\LaravelPhone\\Casts\\E164PhoneNumberCast']),
            self::PostalCode => new Code('PostalCode::class', ['App\\Casts\\PostalCode']),
            self::Isbn => new Code('Isbn::class', ['App\\Casts\\Isbn']),
            self::Money => new Code('Money::class.'.var_export(':'.($settings['currency'] === 'per_record' ? 'per_record,'.self::currencyColumn($field) : $settings['currency']), true), ['App\\Casts\\Money']),
            default => null,
        };
    }

    /**
     * Get the factory expression for a value of the field.
     *
     * @param  Field  $field
     */
    public function fake(array $field): string
    {
        $settings = self::settings($field);

        return match ($this) {
            self::String => 'fake()->words(3, true)',
            self::Text => 'fake()->paragraph()',
            self::Integer => 'fake()->numberBetween(1, 100)',
            self::Decimal => 'fake()->randomFloat(2, 1, 1000)',
            self::Boolean => 'fake()->boolean()',
            self::Date => 'fake()->date()',
            self::DateTime => 'fake()->dateTime()',
            self::Email => 'fake()->safeEmail()',
            self::Choice => 'fake()->randomElement('.self::list($field['choices']).')',
            self::BelongsTo => $field['of'].'::factory()',
            self::Phone, self::PostalCode, self::Isbn, self::Pattern, self::Country, self::Currency => 'fake()->randomElement('.self::list(array_column($this->examples($field)['stored'], 1)).')',
            self::Url => in_array('http', $settings['schemes'], true) ? 'fake()->url()' : "'https://'.fake()->domainName().'/'.fake()->slug(2)",
            self::Money => self::places($settings['currency'] === 'per_record' ? null : $settings['currency']) === 0 ? 'fake()->numberBetween(1, 1000)' : 'fake()->randomFloat(2, 1, 1000)',
            self::Percentage => 'fake()->randomFloat(2, 0, 100)',
        };
    }

    /**
     * Get fixed examples of what the field accepts, what it refuses, and
     * what is stored for what is typed. The scaffold's tests and the form
     * probe use them; a kind of field with no format has none.
     *
     * @param  Field  $field
     * @return Examples
     */
    public function examples(array $field): array
    {
        $settings = self::settings($field);
        $none = ['valid' => [], 'invalid' => [], 'stored' => []];

        return match ($this) {
            self::Phone => self::merged(array_map(fn (string $region) => [
                'CA' => ['valid' => ['(250) 555-0123', '+1 250 555 0123'], 'invalid' => ['12345', 'call me'], 'stored' => [['(250) 555-0123', '+12505550123']]],
                'US' => ['valid' => ['(212) 555-0123', '+1 212 555 0123'], 'invalid' => ['12345', 'call me'], 'stored' => [['(212) 555-0123', '+12125550123']]],
                'GB' => ['valid' => ['020 7946 0123', '+44 20 7946 0123'], 'invalid' => ['12345', 'call me'], 'stored' => [['020 7946 0123', '+442079460123']]],
                'NG' => ['valid' => ['0803 123 4567', '+234 803 123 4567'], 'invalid' => ['12345', 'call me'], 'stored' => [['0803 123 4567', '+2348031234567']]],
                'any' => ['valid' => ['+1 250 555 0123', '+44 20 7946 0123'], 'invalid' => ['12345', 'call me'], 'stored' => [['+1 (250) 555-0123', '+12505550123']]],
            ][$region], self::strings($settings['regions']))),
            self::PostalCode => self::merged(array_map(fn (string $region) => [
                'CA' => ['valid' => ['V6B 1A1', 'v6b1a1'], 'invalid' => ['V6B 1A', '!!'], 'stored' => [['v6b1a1', 'V6B 1A1']]],
                'US' => ['valid' => ['94103', '94103-1234'], 'invalid' => ['9410', '!!'], 'stored' => [[' 94103 ', '94103']]],
                'GB' => ['valid' => ['SW1A 1AA', 'sw1a1aa'], 'invalid' => ['SW1A', '!!'], 'stored' => [['sw1a1aa', 'SW1A 1AA']]],
                'NG' => ['valid' => ['100001'], 'invalid' => ['10001', '!!'], 'stored' => [['100001', '100001']]],
                'any' => ['valid' => ['V6B 1A1', '94103'], 'invalid' => ['A', '!!'], 'stored' => [['v6b 1a1', 'V6B 1A1']]],
            ][$region], self::strings($settings['regions']))),
            self::Url => [
                'valid' => in_array('http', $settings['schemes'], true) ? ['https://example.com/page', 'http://example.com'] : ['https://example.com/page'],
                'invalid' => in_array('http', $settings['schemes'], true) ? ['example', 'ftp://example.com'] : ['example', 'http://example.com', 'ftp://example.com'],
                'stored' => [['https://example.com/a?b=1', 'https://example.com/a?b=1']],
            ],
            self::Isbn => match ($settings['variants']) {
                [10] => ['valid' => ['0-306-40615-2', '1861972717'], 'invalid' => ['0-306-40615-3', '978-0-306-40615-7', '12345'], 'stored' => [['0-306-40615-2', '0306406152'], ['1861972717', '1861972717']]],
                [13] => ['valid' => ['978-0-306-40615-7', '9781861972712'], 'invalid' => ['978-0-306-40615-8', '0-306-40615-2', '12345'], 'stored' => [['978-0-306-40615-7', '9780306406157'], ['9781861972712', '9781861972712']]],
                default => ['valid' => ['978-0-306-40615-7', '0-306-40615-2'], 'invalid' => ['978-0-306-40615-8', '0-306-40615-3', '12345'], 'stored' => [['978-0-306-40615-7', '9780306406157'], ['0-306-40615-2', '0306406152']]],
            },
            self::Country => ['valid' => ['CA', 'NG'], 'invalid' => ['XX', 'Canada'], 'stored' => [['CA', 'CA'], ['US', 'US'], ['GB', 'GB'], ['NG', 'NG']]],
            self::Currency => ['valid' => ['USD', 'NGN'], 'invalid' => ['usd', 'DOLLARS'], 'stored' => [['USD', 'USD'], ['CAD', 'CAD'], ['GBP', 'GBP'], ['NGN', 'NGN']]],
            self::Money => match ($settings['currency'] === 'per_record' ? 2 : self::places($settings['currency'])) {
                0 => ['valid' => ['1250', '0'], 'invalid' => ['12.5', '-1', 'abc'], 'stored' => [['1250', '1250']]],
                3 => ['valid' => ['12.505', '0'], 'invalid' => ['12.5055', '-1', 'abc'], 'stored' => [['12.505', '12505']]],
                // With a currency per record, the example is in USD.
                default => ['valid' => ['12.50', '0', '1999'], 'invalid' => ['12.5055', '-1', 'abc'], 'stored' => [['12.50', '1250']]],
            },
            self::Percentage => ['valid' => ['12.5', '0', '100'], 'invalid' => ['100.5', '-1', 'abc'], 'stored' => [['12.5', '12.50']]],
            self::Pattern => ['valid' => self::strings($settings['examples']), 'invalid' => [], 'stored' => array_map(fn (string $example) => [$example, $example], self::strings($settings['examples']))],
            default => $none,
        };
    }

    /**
     * Get the table Laravel uses for the model.
     */
    public static function table(string $model): string
    {
        return Str::snake(Str::pluralStudly($model));
    }

    /**
     * Get the number of decimal places of a currency.
     */
    public static function places(?string $currency): int
    {
        return self::MINOR_UNITS[(string) $currency] ?? 2;
    }

    /**
     * Get the column that holds the currency of an amount chosen per record.
     *
     * @param  Field  $field
     */
    public static function currencyColumn(array $field): string
    {
        return $field['name'].'_currency';
    }

    /**
     * Get a pattern between delimiters, as a regex rule and preg_match take it.
     */
    public static function delimited(string $pattern): string
    {
        return '/'.str_replace('/', '\\/', $pattern).'/u';
    }

    /**
     * @return list<string>
     */
    protected static function regions(mixed $regions): array
    {
        $known = [];

        foreach (self::strings($regions) as $region) {
            $known[] = in_array(strtoupper($region), self::REGIONS, true) ? strtoupper($region) : 'any';
        }

        return array_values(array_unique($known)) ?: ['any'];
    }

    /**
     * @return list<string>
     */
    protected static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_map(strval(...), array_filter($values, is_scalar(...)))) : [];
    }

    /**
     * @param  list<string|int>  $values
     */
    protected static function list(array $values): string
    {
        return '['.implode(', ', array_map(fn (string|int $value) => var_export($value, true), $values)).']';
    }

    /**
     * @param  array<int, Examples>  $examples
     * @return Examples
     */
    protected static function merged(array $examples): array
    {
        $valid = array_values(array_unique(array_merge(...array_column($examples, 'valid'))));

        return [
            'valid' => $valid,
            // Refused by every region asked for: a code of one is not
            // wrong for a field that takes another too.
            'invalid' => array_values(array_diff(array_unique(array_merge(...array_column($examples, 'invalid'))), $valid)),
            'stored' => array_merge(...array_column($examples, 'stored')),
        ];
    }
}
