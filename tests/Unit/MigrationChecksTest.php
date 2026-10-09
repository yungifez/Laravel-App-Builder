<?php

use App\Features\MigrationChecks;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
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
        'risks' => [],
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
            'artisan migrate --pretend --path=database/migrations/a.php --path=database/migrations/b.php --force --no-interaction',
            'artisan db:show --json --no-interaction',
            'artisan db:seed --force --no-interaction',
            'artisan migrate --force --no-interaction',
        ]);
});

it('runs on when printing the SQL or seeding fails, and stops at the first step that fails', function () {
    expect(runMigrationScript([3, 4, 5])['report'])->toBe(['up' => 0, 'down' => 0, 'again' => 0])
        ->and(runMigrationScript([1])['report'])->toBe(['up' => 1, 'down' => -1, 'again' => -1])
        ->and(runMigrationScript([2])['report'])->toBe(['up' => 0, 'down' => 1, 'again' => -1])
        ->and(runMigrationScript([6])['report'])->toBe(['up' => 0, 'down' => 0, 'again' => 1]);
});

it('says a migration did not run when the undo does not name it', function () {
    $run = runMigrationScript([], silent: true);

    expect($run['report'])->toBe(['up' => 1, 'down' => -1, 'again' => -1])
        ->and($run['calls'])->toHaveCount(2);
});

/**
 * What `migrate --pretend` prints for the given migrations' statements.
 *
 * @param  array<string, list<string>>  $migrations
 */
function pretended(array $migrations): string
{
    $lines = ['', '   INFO  Running migrations.', ''];

    foreach ($migrations as $name => $statements) {
        $lines[] = "  {$name} ".str_repeat('.', 40).'  ';
        array_push($lines, ...array_map(fn (string $sql) => "  ⇂ {$sql}  ", $statements));
        $lines[] = '';
    }

    return implode("\n", $lines);
}

it('finds statements that lose or lock the live data', function () {
    $pretend = pretended([
        '2026_10_05_000000_change_teams' => [
            'alter table "teams" drop column "notes", add column "owner_id" bigint not null, add column "size" integer not null default \'0\', add column "label" varchar(255) null',
            'alter table "teams" rename column "title" to "name"',
            'alter table "teams" alter column "budget" type numeric(8, 2)',
            'create index "teams_name_index" on "teams" ("name")',
            'drop table "plans"',
            'alter table "seats" rename to "places"',
        ],
        '2026_10_05_000001_create_invoices' => [
            'create table "invoices" ("id" bigserial not null primary key, "total" integer not null)',
            'alter table "invoices" add column "paid" boolean not null',
            'create index "invoices_total_index" on "invoices" ("total")',
            'alter table "teams" add constraint "teams_slug_unique" unique ("slug")',
            'create index concurrently "teams_slug_index" on "teams" ("slug")',
        ],
    ]);

    $rules = array_map(fn (array $risk) => [$risk['rule'], MigrationChecks::place($risk)], MigrationChecks::risks($pretend, postgres: true));

    expect($rules)->toBe([
        ['drops', 'teams.notes'],
        ['requires', 'teams.owner_id'],
        ['renames', 'teams.title'],
        ['changes', 'teams.budget'],
        ['locks', 'teams'],
        ['drops', 'plans'],
        ['renames', 'seats'],
    ])->and(MigrationChecks::risks($pretend, postgres: true)[4])->toMatchArray([
        'migration' => '2026_10_05_000000_change_teams',
        'sql' => 'create index "teams_name_index" on "teams" ("name")',
    ]);
});

it('holds an index against the change only on Postgres, and reads a statement printed over several lines', function () {
    $pretend = pretended(['2026_10_05_000000_index_teams' => ['create index "teams_name_index" on "teams" ("name")']])
        ."\n  2026_10_05_000001_require_names ....\n  ⇂ alter table `teams` modify `name` varchar(255)\n    not null\n";

    expect(array_column(MigrationChecks::risks($pretend, postgres: false), 'rule'))->toBe(['changes'])
        ->and(MigrationChecks::risks($pretend, postgres: false)[0]['sql'])->toBe('alter table `teams` modify `name` varchar(255) not null')
        ->and(array_column(MigrationChecks::risks($pretend, postgres: true), 'rule'))->toBe(['locks', 'changes']);
});

it('leaves out a table SQLite builds again to change it', function () {
    expect(MigrationChecks::risks(pretended(['2026_10_05_000000_link_posts' => [
        'create table "__temp__posts" ("id" integer primary key autoincrement not null, "team_id" integer not null, foreign key("team_id") references "teams"("id"))',
        'insert into "__temp__posts" ("id", "team_id") select "id", "team_id" from "posts"',
        'drop table "posts"',
        'alter table "__temp__posts" rename to "posts"',
    ]]), postgres: false))->toBe([]);
});

it('reads the risks only once the migrations were undone, on the database db:show names', function () {
    $logs = [
        'pretend' => pretended(['2026_10_05_000000_index_teams' => ['create index "teams_name_index" on "teams" ("name")']]),
        'driver' => "Warning\n".json_encode(['platform' => ['config' => ['driver' => 'pgsql']]]),
    ];
    $evidence = MigrationChecks::evidence(['database/migrations/a.php'], [], '{"up":0,"down":0,"again":0}', fn (string $step) => $logs[$step] ?? '');

    expect($evidence['risks'])->toHaveCount(1)
        ->and(MigrationChecks::findings($evidence))->toBe([['kind' => MigrationChecks::RISKY, 'subject' => 'locks teams']])
        ->and(MigrationChecks::finding(MigrationChecks::findings($evidence)[0]))->toContain('->online()')
        ->and(MigrationChecks::evidence(['database/migrations/a.php'], [], '{"up":0,"down":1,"again":-1}', fn (string $step) => $logs[$step] ?? '')['risks'])->toBe([])
        ->and(MigrationChecks::evidence(['database/migrations/a.php'], [], '{"up":0,"down":0,"again":0}', fn (string $step) => $step === 'driver' ? '{"platform":{"config":{"driver":"sqlite"}}}' : ($logs[$step] ?? ''))['risks'])->toBe([]);
});

it('reads what Laravel writes for Postgres', function () {
    $queries = DB::connection('pgsql')->pretend(function () {
        Schema::connection('pgsql')->table('users', function (Blueprint $table) {
            $table->dropColumn('remember_token');
            $table->foreignId('team_id')->constrained();
            $table->string('nickname')->nullable();
            $table->integer('seats')->default(1);
            $table->renameColumn('name', 'full_name');
            $table->text('email')->change();
            $table->index('created_at');
            $table->unique('nickname')->online();
        });
        Schema::connection('pgsql')->dropIfExists('sessions');
        Schema::connection('pgsql')->create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->index();
        });
    });

    $rules = array_map(fn (array $risk) => [$risk['rule'], MigrationChecks::place($risk)], MigrationChecks::risks(pretended(['2026_10_05_000000_change_users' => array_column($queries, 'query')]), postgres: true));

    expect($rules)->toEqualCanonicalizing([
        ['drops', 'users.remember_token'],
        ['requires', 'users.team_id'],
        ['renames', 'users.name'],
        ['changes', 'users.email'],
        ['locks', 'users'],
        ['drops', 'sessions'],
    ]);
});
