<?php

namespace App\Features;

/**
 * The values the form-input probes send (§26.11), kept in one place: one
 * value each field's rules allow, and one of the wrong kind. Formats
 * (§9) are to add their own examples here.
 *
 * A rule list is what the app's validator was given for one field, each
 * rule as text ("max:255"). A rule that is code, not text, arrives as
 * "custom:Name", "closure" or "conditional".
 */
class InputValues
{
    /**
     * Rules that say what kind of value a field takes, most telling first.
     */
    public const KINDS = ['file', 'image', 'mimes', 'mimetypes', 'array', 'boolean', 'accepted', 'declined', 'integer', 'numeric', 'decimal', 'digits', 'digits_between', 'date', 'date_format', 'email', 'url', 'uuid', 'ulid', 'ip', 'json', 'timezone', 'string'];

    /**
     * Get the parameters of a rule, or null when the field does not have
     * it. "max:255" gives ["255"]; "required" gives [].
     *
     * @param  list<string>  $rules
     * @return list<string>|null
     */
    public static function rule(array $rules, string $name): ?array
    {
        foreach ($rules as $rule) {
            [$rule, $parameters] = array_pad(explode(':', $rule, 2), 2, null);

            if ($rule === $name) {
                return $parameters === null || $parameters === '' ? [] : str_getcsv($parameters, escape: '\\');
            }
        }

        return null;
    }

    /**
     * Get the kind of value a field takes, from its rules.
     *
     * @param  list<string>  $rules
     */
    public static function kind(array $rules): ?string
    {
        foreach (self::KINDS as $kind) {
            if (self::rule($rules, $kind) !== null) {
                return match ($kind) {
                    'image', 'mimes', 'mimetypes' => 'file',
                    'accepted', 'declined' => 'boolean',
                    'decimal' => 'numeric',
                    'digits_between' => 'digits',
                    'date_format' => 'date',
                    default => $kind,
                };
            }
        }

        return null;
    }

    /**
     * Get one value the field's rules allow, or null when the rules ask
     * for something the probes cannot make (a pattern, a file, a list).
     * A date is a placeholder the probe test fills in, days from today.
     *
     * @param  list<string>  $rules
     */
    public static function valid(array $rules, string $field): mixed
    {
        if (self::rule($rules, 'regex') !== null || self::rule($rules, 'not_regex') !== null) {
            return null;
        }

        if (($in = self::rule($rules, 'in')) !== null) {
            return $in[0] ?? null;
        }

        if (($exists = self::rule($rules, 'exists')) !== null) {
            return self::exists($exists, $field);
        }

        return match (self::kind($rules)) {
            'file', 'array' => null,
            'json' => '{}',
            'boolean' => self::rule($rules, 'declined') !== null ? false : true,
            'integer', 'numeric' => self::number($rules),
            'digits' => str_repeat('1', (int) (self::rule($rules, 'digits')[0] ?? self::rule($rules, 'digits_between')[0] ?? 1)),
            'date' => ['@date' => 30, 'format' => self::rule($rules, 'date_format')[0] ?? 'Y-m-d'],
            'email' => self::rule($rules, 'unique') !== null ? 'probe-'.substr(md5($field), 0, 6).'@example.com' : 'probe@example.com',
            'url' => 'https://example.com',
            'uuid' => '9b2f2a4e-4f0a-4c39-8f3e-7a1d5e6c2b10',
            'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'ip' => '127.0.0.1',
            'timezone' => 'UTC',
            default => self::text($rules, $field),
        };
    }

    /**
     * Get one value of the wrong kind for the field, or null when its
     * rules name no kind to get wrong.
     *
     * @param  list<string>  $rules
     */
    public static function wrongKind(array $rules): mixed
    {
        return match (self::kind($rules)) {
            'integer', 'numeric', 'digits' => 'not-a-number',
            'boolean' => 'not-a-yes-or-no',
            'date' => 'not-a-date',
            'email' => 'not-an-email',
            'url' => 'not a web address',
            'uuid', 'ulid' => 'not-an-id',
            'ip' => 'not-an-ip',
            'timezone' => 'Not/AZone',
            'array' => 'not-a-list',
            'string' => ['not', 'text'],
            default => null,
        };
    }

    /**
     * Get a value no row has, for a field that must name an existing one.
     *
     * @param  list<string>  $rules
     */
    public static function missingRow(array $rules): mixed
    {
        $column = self::rule($rules, 'exists')[1] ?? 'id';

        return $column === 'id' || str_ends_with($column, '_id') ? 2_000_000_001 : 'no-such-value';
    }

    /**
     * Get a value that is not one of the field's choices.
     *
     * @param  list<string>  $rules
     */
    public static function outsideChoices(array $rules): mixed
    {
        $choices = self::rule($rules, 'in') ?? [];

        return $choices !== [] && array_filter($choices, is_numeric(...)) === $choices ? 987_654_321 : 'not-a-choice';
    }

    /**
     * A placeholder the probe test fills with a column of a row it adds.
     *
     * @param  list<string>  $parameters  The exists rule's table and column
     * @return array{'@exists': string, column: string}
     */
    protected static function exists(array $parameters, string $field): array
    {
        $table = (string) preg_replace('/^.*\./', '', $parameters[0] ?? '');
        $column = $parameters[1] ?? (str_ends_with($field, '_id') ? 'id' : $field);

        return ['@exists' => $table, 'column' => $column === 'NULL' ? 'id' : $column];
    }

    /**
     * @param  list<string>  $rules
     */
    protected static function number(array $rules): int|float
    {
        $min = self::rule($rules, 'min')[0] ?? self::rule($rules, 'between')[0] ?? null;
        $max = self::rule($rules, 'max')[0] ?? self::rule($rules, 'between')[1] ?? null;
        $value = $min !== null ? (float) $min : ($max !== null ? min(1, (float) $max) : 1);

        return self::rule($rules, 'integer') !== null || floor($value) === $value ? (int) ceil($value) : $value;
    }

    /**
     * Text as long as the field's rules allow, in a case they accept.
     *
     * @param  list<string>  $rules
     */
    protected static function text(array $rules, string $field): string
    {
        $text = self::rule($rules, 'starts_with')[0] ?? '';
        $text .= self::rule($rules, 'alpha') !== null || self::rule($rules, 'alpha_num') !== null ? 'Probe' : 'Probe-'.substr(md5($field), 0, 6);
        $min = (int) (self::rule($rules, 'size')[0] ?? self::rule($rules, 'min')[0] ?? self::rule($rules, 'between')[0] ?? 0);
        $max = self::rule($rules, 'size')[0] ?? self::rule($rules, 'max')[0] ?? self::rule($rules, 'between')[1] ?? null;
        $text = str_pad($text, $min, 'x');
        $text = $max === null ? $text : substr($text, 0, max(1, (int) $max));

        return match (true) {
            self::rule($rules, 'lowercase') !== null => strtolower($text),
            self::rule($rules, 'uppercase') !== null => strtoupper($text),
            default => $text,
        };
    }
}
