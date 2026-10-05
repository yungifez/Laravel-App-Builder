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
     * The kinds the owner may say they want, such as a data migration that
     * cannot be undone on purpose.
     */
    public const OWNED = [self::FAILS, self::EDITED];

    /**
     * The steps, in the order they run.
     */
    public const STEPS = ['up', 'down', 'again'];

    /**
     * The most output lines kept of a failed step.
     */
    protected const KEPT_LINES = 20;

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
     * on what is there. Each step's output goes to its own log in
     * $directory, and the exit codes to report.json there (-1 for a step
     * that did not run).
     */
    public static function script(string $directory): string
    {
        $directory = rtrim($directory, '/');

        return implode("\n", [
            'set -f',
            "d={$directory}",
            'rm -rf "$d" && mkdir -p "$d"',
            // Every migration that ran is looked at, whatever its batch, and
            // only the added ones are undone.
            'p="--step=1000000"; for f in "$@"; do p="$p --path=$f"; done',
            'run() { s=$1; shift; php artisan "$@" --force --no-interaction > "$d/$s.log" 2>&1; }',
            'down=-1; again=-1',
            'run up migrate; up=$?',
            'if [ $up = 0 ]; then run down migrate:rollback $p; down=$?; fi',
            'if [ $down = 0 ]; then for f in "$@"; do grep -q "$(basename "$f" .php)" "$d/down.log" || up=1; done; fi',
            'if [ $up != 0 ] && [ $down = 0 ]; then cp "$d/down.log" "$d/up.log"; down=-1; fi',
            'if [ $down = 0 ]; then run seed db:seed || true; run again migrate; again=$?; fi',
            'printf \'{"up":%d,"down":%d,"again":%d}\' $up $down $again > "$d/report.json"',
        ]);
    }

    /**
     * Get the evidence kept on the change: the migrations it added and
     * edited, and for the added ones whether each step passed, failed or
     * did not run (null), with the last lines the first failed step wrote.
     * The steps are all null when there is no whole report. Null when the
     * change neither adds nor edits a migration.
     *
     * @param  list<string>  $added
     * @param  list<string>  $edited
     * @param  callable(string): string  $log  Reads a step's log by step name
     * @return array{added: list<string>, edited: list<string>, up: bool|null, down: bool|null, again: bool|null, failed: string|null, output: string|null}|null
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
        ];
    }

    /**
     * Get the findings: the step that failed, and each migration that was
     * edited, less those the owner said they want.
     *
     * @param  array{edited: list<string>, failed: string|null}|null  $evidence
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

        $output = ($evidence['output'] ?? null) === null ? '' : "\n\n".$evidence['output'];

        return match ($finding['subject']) {
            'up' => __('The new migrations did not run. Make each one run on the app as it is.'),
            'down' => __('The new migrations could not be undone. Give each one a down() method that puts the database back as it was.'),
            default => __('The new migrations did not run again after they were undone, on a database holding the app\'s seeded records. Make each down() method undo everything its up() method did, and make up() work on tables that already hold records.'),
        }.$output;
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
