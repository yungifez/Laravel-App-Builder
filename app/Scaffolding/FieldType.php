<?php

namespace App\Scaffolding;

use Illuminate\Support\Str;

/**
 * The kinds of field a data shape may hold, and the fixed table that maps
 * each one to its column, validation rules, cast and factory value (§9,
 * delegation by certainty). No model is asked: the same field always gives
 * the same code.
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

    /**
     * Get the attribute the field is stored in.
     *
     * @param  array{name: string, type: string, required: bool, choices: list<string>, of: string|null}  $field
     */
    public static function attribute(array $field): string
    {
        return self::from($field['type']) === self::BelongsTo ? $field['name'].'_id' : $field['name'];
    }

    /**
     * Get the migration line that adds the field's column.
     *
     * @param  array{name: string, type: string, required: bool, choices: list<string>, of: string|null}  $field
     */
    public function column(array $field): string
    {
        $name = var_export(self::attribute($field), true);
        $nullable = $field['required'] ? '' : '->nullable()';

        return match ($this) {
            self::String, self::Email, self::Choice => "\$table->string({$name}){$nullable};",
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
        };
    }

    /**
     * Get the validation rules for the field.
     *
     * @param  array{name: string, type: string, required: bool, choices: list<string>, of: string|null}  $field
     * @return list<string>
     */
    public function rules(array $field): array
    {
        $presence = $this === self::Boolean ? 'sometimes' : ($field['required'] ? 'required' : 'nullable');

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
        }];
    }

    /**
     * Get the cast for the field, if it needs one.
     */
    public function cast(): ?string
    {
        return match ($this) {
            self::Integer => 'integer',
            self::Decimal => 'decimal:2',
            self::Boolean => 'boolean',
            self::Date => 'date',
            self::DateTime => 'datetime',
            default => null,
        };
    }

    /**
     * Get the factory expression for a value of the field.
     *
     * @param  array{name: string, type: string, required: bool, choices: list<string>, of: string|null}  $field
     */
    public function fake(array $field): string
    {
        return match ($this) {
            self::String => 'fake()->words(3, true)',
            self::Text => 'fake()->paragraph()',
            self::Integer => 'fake()->numberBetween(1, 100)',
            self::Decimal => 'fake()->randomFloat(2, 1, 1000)',
            self::Boolean => 'fake()->boolean()',
            self::Date => 'fake()->date()',
            self::DateTime => 'fake()->dateTime()',
            self::Email => 'fake()->safeEmail()',
            self::Choice => 'fake()->randomElement(['.implode(', ', array_map(fn (string $choice) => var_export($choice, true), $field['choices'])).'])',
            self::BelongsTo => $field['of'].'::factory()',
        };
    }

    /**
     * Get the table Laravel uses for the model.
     */
    public static function table(string $model): string
    {
        return Str::snake(Str::pluralStudly($model));
    }
}
