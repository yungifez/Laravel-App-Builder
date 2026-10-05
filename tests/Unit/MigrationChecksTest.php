<?php

use App\Features\MigrationChecks;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A patch that adds, edits, removes and moves files.
 */
function migrationPatch(): string
{
    return implode("\n", [
        'diff --git a/database/migrations/2026_10_05_000000_add_notes_to_teams.php b/database/migrations/2026_10_05_000000_add_notes_to_teams.php',
        'new file mode 100644',
        '--- /dev/null',
        '+++ b/database/migrations/2026_10_05_000000_add_notes_to_teams.php',
        '@@ -0,0 +1 @@',
        '+<?php',
        'diff --git a/database/migrations/2026_01_01_000000_create_teams_table.php b/database/migrations/2026_01_01_000000_create_teams_table.php',
        '--- a/database/migrations/2026_01_01_000000_create_teams_table.php',
        '+++ b/database/migrations/2026_01_01_000000_create_teams_table.php',
        '@@ -1 +1 @@',
        '-old',
        '+new',
        'diff --git a/database/migrations/2026_01_02_000000_create_plans_table.php b/database/migrations/2026_01_02_000000_create_plans_table.php',
        'deleted file mode 100644',
        '--- a/database/migrations/2026_01_02_000000_create_plans_table.php',
        '+++ /dev/null',
        '@@ -1 +0,0 @@',
        '-<?php',
        'diff --git a/database/migrations/2026_01_03_000000_create_seats_table.php b/database/migrations/archive/2026_01_03_000000_create_seats_table.php',
        'similarity index 100%',
        'rename from database/migrations/2026_01_03_000000_create_seats_table.php',
        'rename to database/migrations/archive/2026_01_03_000000_create_seats_table.php',
        'diff --git a/app/Models/Team.php b/app/Models/Team.php',
        '--- a/app/Models/Team.php',
        '+++ b/app/Models/Team.php',
        '@@ -1 +1 @@',
        '-a',
        '+b',
        'diff --git a/database/seeders/DatabaseSeeder.php b/database/seeders/DatabaseSeeder.php',
        'new file mode 100644',
        '--- /dev/null',
        '+++ b/database/seeders/DatabaseSeeder.php',
        '@@ -0,0 +1 @@',
        '+<?php',
        '',
    ]);
}

it('finds the migrations a change adds', function () {
    expect(MigrationChecks::added(migrationPatch()))->toBe(['database/migrations/2026_10_05_000000_add_notes_to_teams.php'])
        ->and(MigrationChecks::added(null))->toBe([]);
});

it('finds each existing migration a change edits, removes or moves', function () {
    expect(MigrationChecks::edited(migrationPatch()))->toBe([
        'database/migrations/2026_01_01_000000_create_teams_table.php',
        'database/migrations/2026_01_02_000000_create_plans_table.php',
        'database/migrations/2026_01_03_000000_create_seats_table.php',
    ]);
});

it('reads each step of the report, and the output of the first that failed', function () {
    $added = ['database/migrations/2026_10_05_000000_add_notes_to_teams.php'];
    $logs = ['down' => "Rolling back\nSQLSTATE[42S02]: Base table or view not found"];

    expect(MigrationChecks::evidence($added, [], '{"up":0,"down":1,"again":-1}', fn (string $step) => $logs[$step]))->toBe([
        'added' => $added,
        'edited' => [],
        'up' => true,
        'down' => false,
        'again' => null,
        'failed' => 'down',
        'output' => "Rolling back\nSQLSTATE[42S02]: Base table or view not found",
    ])->and(MigrationChecks::evidence($added, [], '{"up":0,"down":0,"again":0}', fn () => ''))->toMatchArray(['up' => true, 'down' => true, 'again' => true, 'failed' => null, 'output' => null]);
});

it('keeps only the last lines a failed step wrote', function () {
    $output = implode("\n", range(1, 50));

    expect(MigrationChecks::evidence(['database/migrations/a.php'], [], '{"up":1,"down":-1,"again":-1}', fn () => $output)['output'])->toBe(implode("\n", range(31, 50)));
});

it('leaves the steps unknown without a whole report, and says nothing without migrations', function () {
    $unknown = ['up' => null, 'down' => null, 'again' => null, 'failed' => null];

    expect(MigrationChecks::evidence([], [], null, fn () => ''))->toBeNull()
        ->and(MigrationChecks::evidence(['database/migrations/a.php'], [], null, fn () => ''))->toMatchArray($unknown)
        ->and(MigrationChecks::evidence(['database/migrations/a.php'], [], '{"up":0,"down":0}', fn () => ''))->toMatchArray($unknown)
        ->and(MigrationChecks::evidence(['database/migrations/a.php'], [], '{"up":"0","down":0,"again":0}', fn () => ''))->toMatchArray($unknown)
        ->and(MigrationChecks::evidence([], ['database/migrations/b.php'], null, fn () => ''))->toMatchArray(['added' => [], 'edited' => ['database/migrations/b.php'], ...$unknown]);
});

it('finds the failed step and each edited migration, less what the owner accepted', function () {
    $evidence = MigrationChecks::evidence(['database/migrations/a.php'], ['database/migrations/2026_01_01_000000_create_teams_table.php'], '{"up":0,"down":0,"again":1}', fn () => '');
    $findings = MigrationChecks::findings($evidence);

    expect($findings)->toBe([
        ['kind' => MigrationChecks::FAILS, 'subject' => 'again'],
        ['kind' => MigrationChecks::EDITED, 'subject' => 'database/migrations/2026_01_01_000000_create_teams_table.php'],
    ])->and(MigrationChecks::findings($evidence, [MigrationChecks::identity($findings[0]), MigrationChecks::identity($findings[1])]))->toBe([])
        ->and(MigrationChecks::findings(null))->toBe([]);
});

it('tells the coder what to fix in plain words, with what the step wrote', function () {
    expect(MigrationChecks::finding(['kind' => MigrationChecks::FAILS, 'subject' => 'down'], ['output' => 'Base table not found']))
        ->toBe("The new migrations could not be undone. Give each one a down() method that puts the database back as it was.\n\nBase table not found")
        ->and(MigrationChecks::finding(['kind' => MigrationChecks::EDITED, 'subject' => 'database/migrations/x.php']))
        ->toContain('database/migrations/x.php, a migration that already exists')
        ->toContain('add a new migration instead');
});

/**
 * Run the script with a stand-in for php that records each artisan call,
 * echoes its arguments unless $silent, and fails the calls in $fail, each
 * counted from 1.
 *
 * @param  list<int>  $fail
 * @return array{report: array<string, int>, calls: list<string>}
 */
function runMigrationScript(array $fail, bool $silent = false): array
{
    $dir = sys_get_temp_dir().'/migration-checks-'.uniqid();
    File::ensureDirectoryExists("{$dir}/bin");
    File::put("{$dir}/bin/php", implode("\n", [
        '#!/bin/sh',
        'n=$(( $(cat "$CALLS" 2>/dev/null | wc -l) + 1 ))',
        'echo "$*" >> "$CALLS"',
        '[ -n "$SILENT" ] || echo "$*"',
        'for f in $FAIL; do [ "$f" = "$n" ] && exit 1; done',
        'exit 0',
    ]));
    chmod("{$dir}/bin/php", 0755);

    Process::path($dir)
        ->env(['PATH' => "{$dir}/bin:".getenv('PATH'), 'CALLS' => "{$dir}/calls", 'FAIL' => implode(' ', $fail), 'SILENT' => $silent ? '1' : ''])
        ->run(['sh', '-c', MigrationChecks::script('logs/migrations'), 'sh', 'database/migrations/a.php', 'database/migrations/b.php'])
        ->throw();

    $result = [
        'report' => json_decode(File::get("{$dir}/logs/migrations/report.json"), true),
        'calls' => explode("\n", trim(File::get("{$dir}/calls"))),
    ];
    File::deleteDirectory($dir);

    return $result;
}

it('migrates, undoes the added migrations, then seeds and runs them again', function () {
    $run = runMigrationScript([]);

    expect($run['report'])->toBe(['up' => 0, 'down' => 0, 'again' => 0])
        ->and($run['calls'])->toBe([
            'artisan migrate --force --no-interaction',
            'artisan migrate:rollback --step=1000000 --path=database/migrations/a.php --path=database/migrations/b.php --force --no-interaction',
            'artisan db:seed --force --no-interaction',
            'artisan migrate --force --no-interaction',
        ]);
});

it('runs on when seeding fails, and stops at the first step that fails', function () {
    expect(runMigrationScript([3])['report'])->toBe(['up' => 0, 'down' => 0, 'again' => 0])
        ->and(runMigrationScript([1])['report'])->toBe(['up' => 1, 'down' => -1, 'again' => -1])
        ->and(runMigrationScript([2])['report'])->toBe(['up' => 0, 'down' => 1, 'again' => -1])
        ->and(runMigrationScript([4])['report'])->toBe(['up' => 0, 'down' => 0, 'again' => 1]);
});

it('says a migration did not run when the undo does not name it', function () {
    $run = runMigrationScript([], silent: true);

    expect($run['report'])->toBe(['up' => 1, 'down' => -1, 'again' => -1])
        ->and($run['calls'])->toHaveCount(2);
});
