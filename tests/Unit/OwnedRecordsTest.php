<?php

use App\Features\OwnedRecords;
use Tests\TestCase;

uses(TestCase::class);

const OWNER_COLUMNS = ['user_id', 'team_id'];

/**
 * A patch that adds each of the given files.
 *
 * @param  list<string>  $paths
 */
function addingPatch(array $paths): string
{
    return implode("\n", array_merge(...array_map(fn (string $path) => [
        "diff --git a/{$path} b/{$path}",
        'new file mode 100644',
        '--- /dev/null',
        "+++ b/{$path}",
        '@@ -0,0 +1 @@',
        '+<?php',
    ], $paths)))."\n";
}

/**
 * A migration that runs the given lines inside Schema::create or
 * Schema::table for the table.
 */
function ownerMigration(string $table, array $lines, string $method = 'create'): string
{
    return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::{$method}('{$table}', function (Blueprint \$table) {\n"
        .implode("\n", array_map(fn (string $line) => "            {$line}", $lines))
        ."\n        });\n    }\n};\n";
}

function ownerModel(string $class, string $body = '', string $attributes = ''): string
{
    return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Attributes\\ScopedBy;\nuse Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy;\nuse Illuminate\\Database\\Eloquent\\Model;\n\n{$attributes}\nclass {$class} extends Model\n{\n{$body}\n}\n";
}

function ownerPolicy(string $class, string $check): string
{
    return "<?php\n\nnamespace App\\Policies;\n\nclass {$class}\n{\n    public function view(\$user, \$record): bool\n    {\n        return {$check};\n    }\n}\n";
}

it('finds the owner columns a change adds, by table', function () {
    $files = [
        'database/migrations/2026_10_05_000000_create_bookings_table.php' => ownerMigration('bookings', [
            '$table->id();',
            '$table->foreignId(\'user_id\')->constrained();',
            '$table->foreignIdFor(App\\Models\\Team::class);',
            '$table->foreignId(\'room_id\');',
            '$table->string(\'user_id_note\');',
        ]),
        'database/migrations/2026_10_05_000001_add_team_to_rooms.php' => ownerMigration('rooms', ['$table->unsignedBigInteger(\'team_id\')->nullable();'], 'table'),
    ];

    expect(OwnedRecords::columns(addingPatch(array_keys($files)), OWNER_COLUMNS, fn (string $path) => $files[$path] ?? null))->toBe([
        ['table' => 'bookings', 'column' => 'user_id', 'at' => 'database/migrations/2026_10_05_000000_create_bookings_table.php:13'],
        ['table' => 'bookings', 'column' => 'team_id', 'at' => 'database/migrations/2026_10_05_000000_create_bookings_table.php:14'],
        ['table' => 'rooms', 'column' => 'team_id', 'at' => 'database/migrations/2026_10_05_000001_add_team_to_rooms.php:12'],
    ]);
});

it('says what keeps each owner\'s records apart: a policy that reads the owner, or a global scope', function () {
    $migrations = [
        'database/migrations/2026_10_05_000000_create_bookings_table.php' => ownerMigration('bookings', ['$table->foreignId(\'user_id\');']),
        'database/migrations/2026_10_05_000001_create_notes_table.php' => ownerMigration('notes', ['$table->foreignId(\'user_id\');']),
        'database/migrations/2026_10_05_000002_create_invoices_table.php' => ownerMigration('invoices', ['$table->foreignId(\'team_id\');']),
        'database/migrations/2026_10_05_000003_create_tasks_table.php' => ownerMigration('tasks', ['$table->foreignId(\'team_id\');']),
        'database/migrations/2026_10_05_000004_create_team_user_table.php' => ownerMigration('team_user', ['$table->foreignId(\'team_id\');', '$table->foreignId(\'user_id\');']),
    ];
    $files = [
        ...$migrations,
        'app/Models/Booking.php' => ownerModel('Booking'),
        'app/Policies/BookingPolicy.php' => ownerPolicy('BookingPolicy', '$user->id === $record->user_id'),
        'app/Models/Note.php' => ownerModel('Note'),
        'app/Policies/NotePolicy.php' => ownerPolicy('NotePolicy', 'true'),
        'app/Models/Invoice.php' => ownerModel('Invoice', attributes: '#[ScopedBy(TeamScope::class)]'),
        'app/Models/Task.php' => ownerModel('Task', attributes: '#[UsePolicy(\\App\\Policies\\WorkPolicy::class)]'),
        'app/Policies/WorkPolicy.php' => ownerPolicy('WorkPolicy', '$user->belongsToTeam($record->team)'),
    ];

    $owned = OwnedRecords::inPatch(addingPatch(array_keys($files)), OWNER_COLUMNS, fn (string $path) => $files[$path] ?? null);

    expect(array_map(fn (array $record) => [$record['model'], $record['guard'], $record['policy']], $owned))->toBe([
        ['App\Models\Booking', 'policy', 'App\Policies\BookingPolicy'],
        ['App\Models\Note', null, 'App\Policies\NotePolicy'],
        ['App\Models\Invoice', 'scope', null],
        ['App\Models\Task', 'policy', 'App\Policies\WorkPolicy'],
    ]);
});

it('finds the policy a provider registers, and a model that names its own table', function () {
    $files = [
        'database/migrations/2026_10_05_000000_create_room_bookings_table.php' => ownerMigration('room_bookings', ['$table->foreignId(\'user_id\');']),
        'app/Models/Reservation.php' => ownerModel('Reservation', "    protected \$table = 'room_bookings';"),
        'app/Providers/AppServiceProvider.php' => "<?php\n\nnamespace App\\Providers;\n\nuse App\\Models\\Reservation;\nuse App\\Policies\\StayPolicy;\nuse Illuminate\\Support\\Facades\\Gate;\n\nclass AppServiceProvider\n{\n    public function boot(): void\n    {\n        Gate::policy(Reservation::class, StayPolicy::class);\n    }\n}\n",
        'app/Policies/StayPolicy.php' => ownerPolicy('StayPolicy', '$record->user?->is($user)'),
    ];

    $owned = OwnedRecords::inPatch(addingPatch(array_keys($files)), OWNER_COLUMNS, fn (string $path) => $files[$path] ?? null);

    expect($owned)->toBe([[
        'table' => 'room_bookings',
        'column' => 'user_id',
        'at' => 'database/migrations/2026_10_05_000000_create_room_bookings_table.php:12',
        'model' => 'App\Models\Reservation',
        'guard' => 'policy',
        'policy' => 'App\Policies\StayPolicy',
    ]]);
});

it('finds each model with nothing that keeps its records apart, less what the owner accepted, and tells the coder what to add', function () {
    $owned = [
        ['model' => 'App\Models\RoomBooking', 'table' => 'room_bookings', 'column' => 'team_id', 'at' => 'database/migrations/x.php:12', 'guard' => null, 'policy' => null],
        ['model' => 'App\Models\Note', 'table' => 'notes', 'column' => 'user_id', 'at' => 'database/migrations/y.php:12', 'guard' => null, 'policy' => 'App\Policies\NotePolicy'],
        ['model' => 'App\Models\Booking', 'table' => 'bookings', 'column' => 'user_id', 'at' => 'database/migrations/z.php:12', 'guard' => 'policy', 'policy' => 'App\Policies\BookingPolicy'],
    ];
    $findings = OwnedRecords::findings($owned);

    expect($findings)->toBe([
        ['kind' => OwnedRecords::UNGUARDED, 'subject' => 'App\Models\RoomBooking'],
        ['kind' => OwnedRecords::UNGUARDED, 'subject' => 'App\Models\Note'],
    ])->and(OwnedRecords::findings($owned, [OwnedRecords::identity($findings[0])]))->toBe([$findings[1]])
        ->and(OwnedRecords::findings(null))->toBe([])
        ->and(OwnedRecords::finding($findings[0], $owned))->toBe('room_bookings gets team_id (database/migrations/x.php:12), so its records belong to someone, but nothing keeps one owner\'s App\Models\RoomBooking records from another. Add a policy that checks team_id against the signed-in person, and authorize each route that reads or changes them. If anyone may see them, ask the owner to keep it.')
        ->and(OwnedRecords::finding($findings[1], $owned))->toContain('App\Models\Note\'s policy never reads it')
        ->and(OwnedRecords::name('App\Models\RoomBooking'))->toBe('room bookings');
});
