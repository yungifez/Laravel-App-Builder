<?php

namespace App\Features;

use App\Scaffolding\FieldType;
use Locale;

/**
 * A change that makes a field's format stricter (§9 Formats): a phone
 * number from any country becomes Canadian only, or an ISBN of either
 * length becomes 13 digits only. Widening is safe; narrowing can make
 * records people already saved fail the new rule. Verification counts
 * them in the app on show, and the owner decides: keep accepting them,
 * or turn them away from now on. Saved rows are never changed either way.
 *
 * A narrowing is read from the generated rules a change edits: the same
 * field's rule removed and added again with fewer countries, lengths or
 * schemes. Only the count leaves the app, never a saved value.
 */
class NarrowedFormats
{
    /**
     * A format made stricter while saved rows fail it.
     */
    public const NARROWED = 'format_narrowed';

    /**
     * The kinds the owner may keep: turning the saved rows away from now on.
     */
    public const OWNED = [self::NARROWED];

    /**
     * The files read: the app's form requests, where the rules are.
     */
    protected const FILES = '/^app\/Http\/Requests\/(?:.*\/)?(?:Store|Update)(\w+)Request\.php$/';

    /**
     * A rule line, by the field it is for.
     */
    protected const LINE = '/^\s*[\'"]([a-z][a-z0-9_]*)[\'"]\s*=>\s*(.+)$/';

    /**
     * Find the fields whose format the patch makes stricter, once each.
     *
     * @return list<array{path: string, table: string, column: string, kind: string, before: list<string>, after: list<string>}>
     */
    public static function inPatch(?string $patch): array
    {
        $found = [];

        foreach (PatchSummary::files($patch) as $file) {
            if (preg_match(self::FILES, $file['path'], $model) !== 1 || str_contains($file['diff'], "\ndeleted file mode ")) {
                continue;
            }

            [$removed, $added] = self::rules($file['diff']);

            foreach ($added as $column => $rule) {
                $before = self::format($removed[$column] ?? '');
                $after = self::format($rule);

                if ($before === null || $after === null || $before['kind'] !== $after['kind'] || ! self::narrower($before['accepts'], $after['accepts'])) {
                    continue;
                }

                $table = FieldType::table($model[1]);
                $found["{$table}.{$column}"] = [
                    'path' => $file['path'],
                    'table' => $table,
                    'column' => $column,
                    'kind' => $after['kind'],
                    'before' => $before['accepts'],
                    'after' => $after['accepts'],
                ];
            }
        }

        return array_values($found);
    }

    /**
     * Get the narrowings with saved rows that fail them, that the owner has
     * not kept.
     *
     * @param  list<array{table: string, column: string, kind: string, after: list<string>, rows?: int|null, failing?: int|null}>|null  $narrowed
     * @param  list<string>  $accepted
     * @return list<array{kind: string, subject: string, things: string, failing: int, after: list<string>, field: string}>
     */
    public static function findings(?array $narrowed, array $accepted = []): array
    {
        $findings = [];

        foreach ($narrowed ?? [] as $format) {
            if (($format['failing'] ?? 0) < 1) {
                continue;
            }

            $finding = [
                'kind' => self::NARROWED,
                'subject' => "{$format['table']}.{$format['column']}",
                'things' => $format['kind'],
                'failing' => (int) $format['failing'],
                'after' => $format['after'],
                'field' => $format['column'],
            ];

            if (! in_array(self::identity($finding), $accepted, true)) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Name a finding the same way each time the checks run.
     *
     * @param  array{kind: string, subject: string}  $finding
     */
    public static function identity(array $finding): string
    {
        return "{$finding['kind']}|{$finding['subject']}";
    }

    /**
     * Say what a finding is, for the agent that sends the change back.
     *
     * @param  array{subject: string, things: string, failing: int, field: string}  $finding
     */
    public static function finding(array $finding): string
    {
        return __(':subject: the change makes the :field rule stricter, and :count saved rows fail it. Keep the rule as wide as it was, so people\'s saved :things stay valid.', [
            'subject' => $finding['subject'],
            'field' => $finding['field'],
            'count' => $finding['failing'],
            'things' => self::things($finding['things']),
        ]);
    }

    /**
     * Ask the owner, with the count in plain words: "12 saved phone
     * numbers are not from Canada. Keep them, or turn them away from now
     * on?"
     *
     * @param  array{things: string, failing: int, after: list<string>}  $finding
     */
    public static function question(array $finding): string
    {
        $count = $finding['failing'];
        $things = self::things($finding['things'], $count);
        $not = $count === 1 ? __('is not') : __('are not');

        return __(':count saved :things :not :where. Keep them, or turn them away from now on?', [
            'count' => $count,
            'things' => $things,
            'not' => $not,
            'where' => self::where($finding['things'], $finding['after'], $count),
        ]);
    }

    /**
     * Say why a narrowing was not counted, for the proof's coverage.
     *
     * @param  array{kind: string, rows?: int|null}  $format
     */
    public static function unchecked(array $format, bool $running): string
    {
        return match (true) {
            ! $running => __('Your app was not running, so saved :things were not checked against the stricter rule.', ['things' => self::things($format['kind'])]),
            ($format['rows'] ?? null) === 0 => __('There are no saved :things to check.', ['things' => self::things($format['kind'])]),
            default => __('Saved :things could not be read, so they were not checked against the stricter rule.', ['things' => self::things($format['kind'])]),
        };
    }

    /**
     * Read the rule lines a diff removes and adds, by field.
     *
     * @return array{array<string, string>, array<string, string>}
     */
    protected static function rules(string $diff): array
    {
        $removed = [];
        $added = [];

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '---') || str_starts_with($line, '+++') || preg_match(self::LINE, substr($line, 1), $rule) !== 1) {
                continue;
            }

            if ($line[0] === '-') {
                $removed[$rule[1]] = $rule[2];
            } elseif ($line[0] === '+') {
                $added[$rule[1]] = $rule[2];
            }
        }

        return [$removed, $added];
    }

    /**
     * Read the format a generated rule line checks, and what it accepts:
     * countries ("any" for every one), ISBN lengths, or URL schemes.
     *
     * @return array{kind: string, accepts: list<string>}|null
     */
    protected static function format(string $rule): ?array
    {
        $list = fn (string $values) => array_values(array_filter(array_map(fn (string $value) => trim($value, " '\""), explode(',', $values)), fn (string $value) => $value !== ''));

        return match (true) {
            preg_match('/[\'"]phone:([A-Z,]+)[\'"]/', $rule, $match) === 1 => ['kind' => FieldType::Phone->value, 'accepts' => array_map(fn (string $region) => $region === 'INTERNATIONAL' ? 'any' : $region, $list($match[1]))],
            preg_match('/new ValidPostalCode\(\[([^\]]*)\]\)/', $rule, $match) === 1 => ['kind' => FieldType::PostalCode->value, 'accepts' => $list($match[1])],
            preg_match('/new ValidIsbn\(\[([^\]]*)\]\)/', $rule, $match) === 1 => ['kind' => FieldType::Isbn->value, 'accepts' => $list($match[1])],
            preg_match('/[\'"]url:([a-z,]+)[\'"]/', $rule, $match) === 1 => ['kind' => FieldType::Url->value, 'accepts' => $list($match[1])],
            default => null,
        };
    }

    /**
     * Determine if a rule now refuses something it accepted before.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    protected static function narrower(array $before, array $after): bool
    {
        if (in_array('any', $after, true)) {
            return false;
        }

        return in_array('any', $before, true) || array_diff($before, $after) !== [];
    }

    protected static function things(string $kind, int $count = 2): string
    {
        // Written out, as Str::plural() turns "ISBN" into "ISBNS".
        [$one, $many] = match ($kind) {
            FieldType::Phone->value => ['phone number', 'phone numbers'],
            FieldType::PostalCode->value => ['postal code', 'postal codes'],
            FieldType::Isbn->value => ['ISBN', 'ISBNs'],
            default => ['web address', 'web addresses'],
        };

        return $count === 1 ? __($one) : __($many);
    }

    /**
     * Say what the stricter rule accepts, after "is not".
     *
     * @param  list<string>  $after
     */
    protected static function where(string $kind, array $after, int $count): string
    {
        return match ($kind) {
            FieldType::Phone->value, FieldType::PostalCode->value => __('from :countries', ['countries' => self::either(array_map(fn (string $region) => (string) Locale::getDisplayRegion("-{$region}", 'en'), $after))]),
            FieldType::Isbn->value => $count === 1
                ? __('a :lengths-digit ISBN', ['lengths' => self::either($after)])
                : __(':lengths-digit ISBNs', ['lengths' => self::either($after)]),
            default => $count === 1 ? __('a secure web address') : __('secure web addresses'),
        };
    }

    /**
     * @param  list<string>  $values
     */
    protected static function either(array $values): string
    {
        $last = array_pop($values);

        return $values === [] ? (string) $last : implode(', ', $values).' '.__('or').' '.$last;
    }
}
