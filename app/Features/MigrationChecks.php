<?php

namespace App\Features;

use Illuminate\Support\Str;

/**
 * Proof that a change's migrations run, read from the framework running
 * them, not from the coder's word (architecture §9 and §12). The migrations
 * a change adds are run up, down and up again on the workspace's own
 * database, with what the app's seeders put there. A migration that does
 * not run, or cannot be undone, breaks the owner's live data when it is
 * published.
 *
 * A change also never edits a migration that already exists: it has
 * already run on every copy of the app, so the edit would never reach
 * their data.
 */
class MigrationChecks
{
    /**
     * Where Laravel keeps an app's migrations.
     */
    public const DIRECTORY = 'database/migrations/';

    /**
     * A migration the change added did not run up, down or up again.
     */
    public const FAILS = 'migration_fails';

    /**
     * The change edited or removed a migration that already exists.
     */
    public const EDITED = 'migration_edited';

    /**
     * An added migration's SQL can lose or lock the live app's data: it
     * drops or renames, changes a column's type, makes a column required
     * with no default, or builds an index on Postgres without CONCURRENTLY.
     */
    public const RISKY = 'migration_risky';

    /**
     * What a risky statement does, by rule.
     */
    public const RULES = ['drops', 'renames', 'changes', 'requires', 'locks'];

    /**
     * The kinds the owner may say they want, such as a data migration that
     * cannot be undone on purpose.
     */
    public const OWNED = [self::FAILS, self::EDITED, self::RISKY];

    /**
     * The steps, in the order they run.
     */
    public const STEPS = ['up', 'down', 'again'];

    /**
     * The most output lines kept of a failed step.
     */
    protected const KEPT_LINES = 20;

    /**
     * How wide the console is told to be while it prints the SQL, so a
     * long statement stays on one line.
     */
    protected const COLUMNS = 4000;

    /**
     * Get the migrations the patch adds.
     *
     * @return list<string>
     */
    public static function added(?string $patch): array
    {
        return array_values(array_map(
            fn (array $file) => $file['path'],
            array_filter(PatchSummary::files($patch), fn (array $file) => self::isMigration($file['path']) && self::isNew($file['diff'])),
        ));
    }

    /**
     * Get the migrations that existed before the change and that the patch
     * edits, removes or moves.
     *
     * @return list<string>
     */
    public static function edited(?string $patch): array
    {
        $edited = [];

        foreach (PatchSummary::files($patch) as $file) {
            $before = Str::of(strtok($file['diff'], "\n") ?: '')->between(' a/', ' b/')->toString();

            if (! self::isNew($file['diff']) && (self::isMigration($before) || self::isMigration($file['path']))) {
                $edited[] = self::isMigration($before) ? $before : $file['path'];
            }
        }

        return array_values(array_unique($edited));
    }

    /**
     * The shell script that proves the added migrations run up, down and up
     * again, with the migration paths as its arguments. "up" migrates the
     * workspace's database, which the app's own tests may have done already;
     * the undo then has to name each added migration, or it never ran.
     * "down" undoes the added ones. "again" fills the database with the
     * app's own seeders and runs them once more, so a down() that left
     * something behind, or an up() that breaks on existing records, fails
     * there. A seeder that fails changes nothing: the migrations then run
     * on what is there. Once they are undone, the SQL they would run is
     * printed without running it ("pretend"), with the database's kind
     * ("driver"), for the rules in risks(). Each step's output goes to its
     * own log in $directory, and the exit codes to report.json there (-1
     * for a step that did not run).
     */
    public static function script(string $directory): string
    {
        $directory = rtrim($directory, '/');

        return implode("\n", [
            'set -f',
            "d={$directory}",
            'rm -rf "$d" && mkdir -p "$d"',
            // Every migration that ran is looked at, whatever its batch, and
            // only the added ones are undone or printed.
            'q=""; for f in "$@"; do q="$q --path=$f"; done',
            'run() { s=$1; shift; php artisan "$@" --no-interaction > "$d/$s.log" 2>&1; }',
            'down=-1; again=-1',
            'run up migrate --force; up=$?',
            'if [ $up = 0 ]; then run down migrate:rollback --step=1000000 $q --force; down=$?; fi',
            'if [ $down = 0 ]; then for f in "$@"; do grep -q "$(basename "$f" .php)" "$d/down.log" || up=1; done; fi',
            'if [ $up != 0 ] && [ $down = 0 ]; then cp "$d/down.log" "$d/up.log"; down=-1; fi',
            'if [ $down = 0 ]; then COLUMNS='.self::COLUMNS.' run pretend migrate --pretend $q --force || true; run driver db:show --json || true; fi',
            'if [ $down = 0 ]; then run seed db:seed --force || true; run again migrate --force; again=$?; fi',
            'printf \'{"up":%d,"down":%d,"again":%d}\' $up $down $again > "$d/report.json"',
        ]);
    }

    /**
     * Get the evidence kept on the change: the migrations it added and
     * edited, and for the added ones whether each step passed, failed or
     * did not run (null), with the last lines the first failed step wrote
     * and the risky statements their SQL holds. The steps are all null when
     * there is no whole report. Null when the change neither adds nor
     * edits a migration.
     *
     * @param  list<string>  $added
     * @param  list<string>  $edited
     * @param  callable(string): string  $log  Reads a step's log by step name
     * @return array{added: list<string>, edited: list<string>, up: bool|null, down: bool|null, again: bool|null, failed: string|null, output: string|null, risks: list<array{rule: string, migration: string, table: string, column: string|null, sql: string}>}|null
     */
    public static function evidence(array $added, array $edited, ?string $report, callable $log): ?array
    {
        if ($added === [] && $edited === []) {
            return null;
        }

        $codes = $added === [] ? null : json_decode(trim((string) $report), true);
        $whole = is_array($codes) && array_diff(self::STEPS, array_keys($codes)) === [] && array_all($codes, fn (mixed $code) => is_int($code));
        $steps = array_combine(self::STEPS, array_map(fn (string $step) => $whole ? ($codes[$step] < 0 ? null : $codes[$step] === 0) : null, self::STEPS));
        $failed = array_find(self::STEPS, fn (string $step) => $steps[$step] === false);

        return [
            'added' => $added,
            'edited' => $edited,
            ...$steps,
            'failed' => $failed,
            'output' => $failed === null ? null : implode("\n", array_slice(explode("\n", trim($log($failed))), -self::KEPT_LINES)),
            'risks' => $steps['down'] === true ? self::risks($log('pretend'), self::isPostgres($log('driver'))) : [],
        ];
    }

    /**
     * Get the statements in the printed SQL that can lose or lock the live
     * app's data, once each by rule, table and column. A table the same
     * migrations create is new and empty, so nothing done to it counts.
     * SQLite builds a table again under "__temp__" to change it, and to add
     * a foreign key, so that table is not read: a type change on SQLite is
     * not seen. An index is only held against the change on Postgres,
     * which alone can build one without locking the table's writes.
     *
     * @return list<array{rule: string, migration: string, table: string, column: string|null, sql: string}>
     */
    public static function risks(string $pretend, bool $postgres): array
    {
        $found = [];
        $created = [];

        foreach (self::statements($pretend) as [$migration, $sql]) {
            $plain = strtolower(str_replace(['"', '`'], '', $sql));

            if (preg_match('/^create table (?:if not exists )?(\S+)/', $plain, $match)) {
                array_push($created, $match[1], Str::after($match[1], '__temp__'));

                continue;
            }

            foreach (self::rules($plain, $postgres) as [$rule, $table, $column]) {
                if (! in_array($table, $created, true)) {
                    $found["{$rule} {$table}.{$column}"] ??= ['rule' => $rule, 'migration' => $migration, 'table' => $table, 'column' => $column, 'sql' => $sql];
                }
            }
        }

        return array_values($found);
    }

    /**
     * Get each statement in the printed SQL with the migration it is in.
     * The console prints a migration's name, then each statement after
     * "⇂"; a statement too long for the console goes on over more lines.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected static function statements(string $pretend): array
    {
        $statements = [];
        $migration = '';
        $sql = null;

        foreach (explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', $pretend)) as $line) {
            $line = trim($line);
            $starts = str_starts_with($line, '⇂');
            $named = ! $starts && (preg_match('/^(\S+)\s*\.{3,}/', $line, $match) || preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6}_\w+)$/', $line, $match));

            if ($sql !== null && ($starts || $named || $line === '')) {
                $statements[] = [$sql[0], $sql[1]];
                $sql = null;
            }

            if ($starts) {
                $sql = [$migration, trim(Str::after($line, '⇂'))];
            } elseif ($named) {
                $migration = $match[1];
            } elseif ($sql !== null) {
                $sql[1] .= ' '.$line;
            }
        }

        return $sql === null ? $statements : [...$statements, [$sql[0], $sql[1]]];
    }

    /**
     * Get what one statement does that can lose or lock data, as rule,
     * table and column.
     *
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    protected static function rules(string $sql, bool $postgres): array
    {
        if (preg_match('/^drop table (?:if exists )?(\S+)/', $sql, $match)) {
            return [['drops', $match[1], null]];
        }

        if (preg_match('/^rename table (\S+)/', $sql, $match)) {
            return [['renames', $match[1], null]];
        }

        if (preg_match('/^create (?:unique )?index (?!concurrently )(?:if not exists )?\S+ on (?:only )?(\S+)/', $sql, $match)) {
            return $postgres ? [['locks', $match[1], null]] : [];
        }

        if (! preg_match('/^alter table (?:if exists )?(?:only )?(\S+) (.*)$/s', $sql, $match)) {
            return [];
        }

        $table = $match[1];
        $found = [];

        // One statement may do several things, such as two added columns.
        foreach (preg_split('/,\s*(?=(?:add|drop|alter|rename|modify|change)\s)/', $match[2]) ?: [] as $part) {
            $part = trim($part);

            if (preg_match('/^rename to /', $part)) {
                $found[] = ['renames', $table, null];
            } elseif (preg_match('/^(?:rename column|rename) (\S+) to /', $part, $column)) {
                $found[] = ['renames', $table, $column[1]];
            } elseif (preg_match('/^drop (?:column )?(?!constraint |index |primary |foreign |unique |key )(?:if exists )?(\S+)/', $part, $column)) {
                $found[] = ['drops', $table, $column[1]];
            } elseif (preg_match('/^alter column (\S+) (?:set data )?type /', $part, $column) || preg_match('/^(?:modify|change) (?:column )?(\S+)/', $part, $column)) {
                $found[] = ['changes', $table, $column[1]];
            } elseif (preg_match('/^alter column (\S+) set not null/', $part, $column)) {
                $found[] = ['requires', $table, $column[1]];
            } elseif (preg_match('/^add (?:column )?(?!constraint |index |unique |primary |foreign |key |fulltext |spatial )(\S+) (.*)$/', $part, $column)
                && str_contains($column[2], 'not null') && ! str_contains($column[2], ' default ') && ! preg_match('/serial|generated|auto_increment|autoincrement/', $column[2])) {
                $found[] = ['requires', $table, $column[1]];
            } elseif ($postgres && preg_match('/^add constraint \S+ (?:unique|primary key)/', $part) && ! str_contains($part, ' using index ')) {
                $found[] = ['locks', $table, null];
            }
        }

        // change() states "not null" again for each column it changes;
        // the change already says more.
        $changed = array_column(array_filter($found, fn (array $risk) => $risk[0] === 'changes'), 2);

        return array_values(array_filter($found, fn (array $risk) => $risk[0] !== 'requires' || ! in_array($risk[2], $changed, true)));
    }

    /**
     * Whether db:show says the workspace's database is Postgres.
     */
    protected static function isPostgres(string $show): bool
    {
        // Only the last line is the report; a warning may come before it.
        $show = json_decode(Str::afterLast(trim($show), "\n"), true);

        return is_array($show) && ($show['platform']['config']['driver'] ?? null) === 'pgsql';
    }

    /**
     * Get the findings: the step that failed, each migration that was
     * edited and each risky statement, less those the owner said they want.
     *
     * @param  array{edited: list<string>, failed: string|null, risks: list<array{rule: string, table: string, column: string|null}>}|null  $evidence
     * @param  list<string>  $accepted  Identities the owner said they want
     * @return list<array{kind: string, subject: string}>
     */
    public static function findings(?array $evidence, array $accepted = []): array
    {
        if ($evidence === null) {
            return [];
        }

        $findings = [
            ...$evidence['failed'] === null ? [] : [['kind' => self::FAILS, 'subject' => $evidence['failed']]],
            ...array_map(fn (string $path) => ['kind' => self::EDITED, 'subject' => $path], $evidence['edited']),
            ...array_map(fn (array $risk) => ['kind' => self::RISKY, 'subject' => $risk['rule'].' '.self::place($risk)], $evidence['risks']),
        ];

        return array_values(array_filter($findings, fn (array $finding) => ! in_array(self::identity($finding), $accepted, true)));
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
     * @param  array{kind: string, subject: string}  $finding
     * @param  array{output: string|null}|null  $evidence
     */
    public static function finding(array $finding, ?array $evidence = null): string
    {
        if ($finding['kind'] === self::EDITED) {
            return __('The change edits :path, a migration that already exists. It has already run on every copy of the app, so the edit would never reach their data. Put it back as it was and add a new migration instead.', ['path' => $finding['subject']]);
        }

        if ($finding['kind'] === self::RISKY) {
            [$rule, $place] = explode(' ', $finding['subject'], 2);

            return match ($rule) {
                'drops' => __('A new migration drops :place. Its records are lost when the change is published. If the request does not ask for that, keep it and stop using it instead. If it does, ask the owner to keep it.', ['place' => $place]),
                'renames' => __('A new migration renames :place. The live app still uses the old name while the change is published, so requests fail until it is out. Add the new one, copy the records across and stop using the old one. If the request asks for exactly this, ask the owner to keep it.', ['place' => $place]),
                'changes' => __('A new migration changes the type of :place. Records that do not fit the new type fail or are cut. Add a new column of the new type and copy the records across instead. If the request asks for exactly this, ask the owner to keep it.', ['place' => $place]),
                'requires' => __('A new migration makes :place required with no default. The table\'s existing records have no value, so it fails on the live app. Give it a default, or make it nullable.', ['place' => $place]),
                default => __('A new migration builds an index on :place and locks the table\'s writes while it builds. Build it with ->online(), which needs $withinTransaction = false on the migration, or ask the owner to keep it when the table stays small.', ['place' => $place]),
            };
        }

        $output = ($evidence['output'] ?? null) === null ? '' : "\n\n".$evidence['output'];

        return match ($finding['subject']) {
            'up' => __('The new migrations did not run. Make each one run on the app as it is.'),
            'down' => __('The new migrations could not be undone. Give each one a down() method that puts the database back as it was.'),
            default => __('The new migrations did not run again after they were undone, on a database holding the app\'s seeded records. Make each down() method undo everything its up() method did, and make up() work on tables that already hold records.'),
        }.$output;
    }

    /**
     * Name where a risky statement acts: the table, or the table's column.
     *
     * @param  array{table: string, column: string|null}  $risk
     */
    public static function place(array $risk): string
    {
        return $risk['column'] === null ? $risk['table'] : "{$risk['table']}.{$risk['column']}";
    }

    /**
     * Whether a path is one of the app's migrations.
     */
    protected static function isMigration(string $path): bool
    {
        return str_starts_with($path, self::DIRECTORY) && str_ends_with($path, '.php');
    }

    /**
     * Whether a file's diff adds it.
     */
    protected static function isNew(string $diff): bool
    {
        return str_contains($diff, "\nnew file mode ") || str_contains($diff, "\n--- /dev/null");
    }
}
