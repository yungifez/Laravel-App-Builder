<?php

namespace Tests\Feature\Features;

use App\Actions\Features\RequestVerification;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class AccessProbeVerificationTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected const ROUTES = ['sh', '-c', 'list the routes'];

    protected const PROBE = ['sh', '-c', 'run the probes', 'sh'];

    protected const SWAP = ['sh', '-c', 'run the swaps', 'sh'];

    /**
     * What the script that finds the app's teams prints.
     */
    protected string $teams = '{"tenants":[],"owned":{}}';

    /**
     * What the script that reads the routes' records prints.
     */
    protected string $bindings = 'Nothing to read.';

    /**
     * What each swap did.
     *
     * @var list<string>
     */
    protected array $swaps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300]],
            'builder.verification.security.enabled' => false,
            'builder.verification.screens.enabled' => false,
            'builder.verification.change_evidence.enabled' => false,
            'builder.verification.access' => [
                'enabled' => true,
                'probes' => 40,
                'test' => 'tests/Feature/AccessProbeTest.php',
                'models' => 'models.php',
                'routes' => ['command' => self::ROUTES, 'report' => 'routes.json'],
                'command' => self::PROBE,
                'timeout' => 60,
                'report' => 'probes.jsonl',
                'swaps' => [
                    'enabled' => true,
                    'limit' => 30,
                    'rows' => 60,
                    'bindings' => 'bindings.php',
                    'test' => 'tests/Feature/SwapProbeTest.php',
                    'command' => self::SWAP,
                    'report' => 'swaps.jsonl',
                ],
            ],
        ]);
    }

    /**
     * A change whose plan says only the person who added a booking may
     * remove it.
     */
    protected function change(): FeatureRequest
    {
        $change = FeatureRequest::factory()->generated()->create();
        Run::factory()->for($change)->create(['plan' => $this->bookingPlan()]);

        return $change;
    }

    /**
     * The plan of a change that says only the person who added a booking
     * may remove it.
     *
     * @return array<string, mixed>
     */
    protected function bookingPlan(): array
    {
        return [
            'summary' => 'Members book rooms.',
            'acceptance_criteria' => ['A member can book a room.'],
            'cases' => [['criterion' => 1, 'kind' => 'base', 'says' => 'A member books a free room.', 'none' => null], ['criterion' => 1, 'kind' => 'alternate', 'says' => 'A member books a second room the same day.', 'none' => null], ['criterion' => 1, 'kind' => 'exception', 'says' => 'A guest is sent to sign in.', 'none' => null]],
            'written_tests' => [],
            'written_files' => [],
            'assumptions' => [],
            'tasks' => ['Let members book rooms.'],
            'steps' => [],
            'acceptance' => [],
            'solution_key' => null,
            'data_shape' => [[
                'name' => 'Booking',
                'fields' => [['name' => 'user', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'User']],
                'access' => ['view' => 'everyone', 'create' => 'everyone', 'update' => 'everyone', 'delete' => 'creator'],
            ]],
        ];
    }

    /**
     * Answer the route list with a delete route for bookings, the policy
     * files with a booking policy, and the probes with what each one did.
     *
     * @param  list<string>  $lines
     */
    protected function answer(array $lines): void
    {
        $this->driver->onExec = function (string $workspace, array $command) use ($lines) {
            if ($command === self::ROUTES) {
                $this->driver->files["{$workspace}:routes.json"] = (string) json_encode([
                    ['domain' => null, 'method' => 'DELETE', 'uri' => 'bookings/{booking}', 'name' => 'bookings.destroy', 'action' => 'App\Http\Controllers\BookingController@destroy', 'middleware' => ['web']],
                ]);
            }

            if ($command === ['find', 'app/Policies', '-name', '*Policy.php', '-type', 'f']) {
                return new CommandResult(exitCode: 0, output: "app/Policies/BookingPolicy.php\napp/Policies/RoomPolicy.php\n", errorOutput: '', durationMs: 5);
            }

            if ($command === ['php', 'models.php']) {
                return new CommandResult(exitCode: 0, output: $this->teams, errorOutput: '', durationMs: 5);
            }

            if (array_slice($command, 0, 4) === self::PROBE) {
                $this->driver->files["{$workspace}:probes.jsonl"] = implode("\n", $lines);
            }

            if ($command === ['php', 'bindings.php']) {
                return new CommandResult(exitCode: 0, output: $this->bindings, errorOutput: '', durationMs: 5);
            }

            if (array_slice($command, 0, 4) === self::SWAP) {
                $this->driver->files["{$workspace}:swaps.jsonl"] = implode("\n", $this->swaps);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
    }

    public function test_a_refused_person_who_got_through_fails_the_checks_and_says_how(): void
    {
        // The visitor was sent to sign in; another person removed the booking.
        $this->answer(['{"id":0,"status":302,"changed":false,"invalid":false}', '{"id":1,"status":302,"changed":true,"invalid":false}']);
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $result = collect($verification->results)->firstWhere('name', 'Who may see and change records');
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $this->assertSame(['checks', 'failed'], [$result['stage'], $result['outcome']]);
        $this->assertStringContainsString('A signed-in person who did not add it could remove a booking: DELETE /bookings/{booking} answered 302.', $result['output']);
        $this->assertSame([], $verification->evidence['earlier_rules'] ?? null, 'the change\'s own rule is not an earlier one');

        // The probe ran with its test's path, and the test was taken out after.
        $commands = array_column($this->driver->executed, 'command');
        $this->assertContains([...self::PROBE, 'tests/Feature/AccessProbeTest.php'], $commands);
        $this->assertContains(['rm', '-f', 'tests/Feature/AccessProbeTest.php'], $commands);
        $this->assertStringContainsString('class AccessProbeTest extends TestCase', $this->written());
    }

    public function test_probes_that_were_all_refused_pass_and_probes_that_proved_nothing_add_no_check(): void
    {
        $this->answer(['{"id":0,"status":302,"changed":false,"invalid":false}', '{"id":1,"status":403,"changed":false,"invalid":false}']);
        $refused = $this->change();

        app(RequestVerification::class)->handle($refused);

        $result = collect($refused->verifications()->sole()->results)->firstWhere('name', 'Who may see and change records');
        $this->assertSame('passed', $result['outcome']);

        $this->answer(['{"id":0,"status":500,"changed":false,"invalid":false}']);
        $broken = $this->change();

        app(RequestVerification::class)->handle($broken);

        $this->assertNull(collect($broken->verifications()->sole()->results)->firstWhere('name', 'Who may see and change records'));
    }

    public function test_a_test_that_already_failed_before_the_change_does_not_stop_the_probes(): void
    {
        $this->failTests(atStart: true);
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $tests = collect($verification->results)->firstWhere('name', 'Tests');
        $this->assertSame(['failed', []], [$tests['at_start'], $tests['new_problems']]);
        $this->assertStringContainsString('could remove a booking', collect($verification->results)->firstWhere('name', 'Who may see and change records')['output']);
        $this->assertSame(VerificationStatus::Failed, $verification->status);
    }

    public function test_a_test_the_change_broke_stops_the_probes(): void
    {
        $this->failTests(atStart: false);
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $this->assertNull(collect($verification->results)->firstWhere('name', 'Who may see and change records'));
        $this->assertSame(VerificationStatus::Failed, $verification->status);
    }

    /**
     * Fail the app's tests with the change in, and, when asked, on the
     * starting commit too. The probes find someone who got through.
     */
    protected function failTests(bool $atStart): void
    {
        $this->answer(['{"id":0,"status":302,"changed":false,"invalid":false}', '{"id":1,"status":302,"changed":true,"invalid":false}']);
        $answer = $this->driver->onExec;
        $started = false;

        $this->driver->onExec = function (string $workspace, array $command) use ($answer, $atStart, &$started) {
            if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'apply') {
                $started = in_array('--reverse', $command, true);
            }

            if ($command === ['php', 'artisan', 'test']) {
                return $started && ! $atStart
                    ? new CommandResult(exitCode: 0, output: 'All tests passed.', errorOutput: '', durationMs: 5)
                    : new CommandResult(exitCode: 1, output: 'FAILED Tests\\Feature\\OldTest > it works', errorOutput: '', durationMs: 5);
            }

            return $answer($workspace, $command);
        };
    }

    public function test_a_plan_without_rules_sends_no_probes(): void
    {
        $this->answer([]);
        $change = FeatureRequest::factory()->generated()->create();
        Run::factory()->for($change)->create();

        app(RequestVerification::class)->handle($change);

        $this->assertNotContains(self::ROUTES, array_column($this->driver->executed, 'command'));
    }

    /**
     * Get the probe test the verification wrote into the workspace.
     */
    protected function written(): string
    {
        foreach ($this->driver->files as $key => $contents) {
            if (str_ends_with($key, ':tests/Feature/AccessProbeTest.php')) {
                return $contents;
            }
        }

        return '';
    }

    public function test_a_record_the_app_had_is_held_to_its_own_policy_where_the_change_touched_its_controller(): void
    {
        $this->answer(['{"id":0,"status":302,"changed":false,"invalid":false,"policy":false}', '{"id":1,"status":302,"changed":true,"invalid":false,"policy":false}']);
        $change = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Http/Controllers/BookingController.php b/app/Http/Controllers/BookingController.php',
            '--- a/app/Http/Controllers/BookingController.php',
            '+++ b/app/Http/Controllers/BookingController.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// Bookings',
            '',
        ])]);

        app(RequestVerification::class)->handle($change);

        $result = collect($change->verifications()->sole()->results)->firstWhere('name', 'Who may see and change records');
        $this->assertSame('failed', $result['outcome']);
        $this->assertStringContainsString("could remove a booking: DELETE /bookings/{booking} answered 302. The app's own Booking policy refuses this.", $result['output']);
    }

    public function test_a_person_outside_the_team_is_tried_instead_of_asking_the_policy(): void
    {
        $this->teams = "Booting.\n".'{"tenants":["Team"],"owned":{"Booking":{"tenant":"Team","key":"team_id"}}}';
        // The policy would allow it; the team rule does not ask.
        $this->answer(['{"id":0,"status":302,"changed":true,"invalid":false,"policy":null}']);
        $change = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Http/Controllers/BookingController.php b/app/Http/Controllers/BookingController.php',
            '--- a/app/Http/Controllers/BookingController.php',
            '+++ b/app/Http/Controllers/BookingController.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// Bookings',
            '',
        ])]);

        app(RequestVerification::class)->handle($change);

        $result = collect($change->verifications()->sole()->results)->firstWhere('name', 'Who may see and change records');
        $this->assertSame('failed', $result['outcome']);
        $this->assertStringContainsString('A signed-in person outside the team could remove a booking', $result['output']);
        $test = $this->written();
        $this->assertSame(1, substr_count($test, "'stranger', "), 'one probe for the outsider, not a second from the policy');
        $this->assertSame(1, substr_count($test, "'guest', "));
    }

    public function test_a_rule_an_earlier_kept_change_stated_is_tried_on_every_later_change(): void
    {
        $this->answer(['{"id":0,"status":302,"changed":false,"invalid":false}', '{"id":1,"status":302,"changed":true,"invalid":false}']);
        $kept = FeatureRequest::factory()->generated()->create(['commit_sha' => str_repeat('a', 40)]);
        Run::factory()->for($kept)->create(['plan' => $this->bookingPlan()]);
        $kept->verifications()->create(['status' => VerificationStatus::Passed, 'results' => [['name' => 'Who may see and change records', 'stage' => 'checks', 'outcome' => 'passed']]]);
        // A change that only touches a page still answers to the rule.
        $change = FeatureRequest::factory()->generated()->for($kept->project)->create();
        Run::factory()->for($change)->create();

        app(RequestVerification::class)->handle($change);

        $result = collect($change->verifications()->sole()->results)->firstWhere('name', 'Who may see and change records');
        $this->assertSame('failed', $result['outcome']);
        $this->assertStringContainsString('A signed-in person who did not add it could remove a booking', $result['output']);
        $this->assertSame(['Booking delete'], $change->verifications()->sole()->evidence['earlier_rules'] ?? null);
    }

    public function test_a_rule_of_an_undone_change_or_one_no_probe_proved_is_not_tried(): void
    {
        $this->answer([]);
        $proved = ['status' => VerificationStatus::Passed, 'results' => [['name' => 'Who may see and change records', 'stage' => 'checks', 'outcome' => 'passed']]];
        $undone = FeatureRequest::factory()->generated()->create(['commit_sha' => str_repeat('a', 40), 'reverted_at' => now()]);
        Run::factory()->for($undone)->create(['plan' => $this->bookingPlan()]);
        $undone->verifications()->create($proved);
        // Kept before its probes ran: nothing proves its rule held then.
        $unproven = FeatureRequest::factory()->generated()->for($undone->project)->create(['commit_sha' => str_repeat('b', 40)]);
        Run::factory()->for($unproven)->create(['plan' => $this->bookingPlan()]);
        $change = FeatureRequest::factory()->generated()->for($undone->project)->create();
        Run::factory()->for($change)->create();

        app(RequestVerification::class)->handle($change);

        $this->assertNotContains(self::ROUTES, array_column($this->driver->executed, 'command'));
    }

    /**
     * A change that touched the projects controller, in an app whose
     * projects belong to a team with members.
     */
    protected function projectChange(): FeatureRequest
    {
        $this->bindings = "Booting.\n".json_encode([
            'user' => 'User',
            'routes' => [
                ['methods' => ['GET'], 'uri' => '/projects/{project}', 'name' => 'projects.show', 'domain' => null, 'controller' => 'App\Http\Controllers\ProjectController', 'action' => 'show', 'params' => [['name' => 'project', 'model' => 'Project', 'field' => null]]],
            ],
            'owners' => ['Project' => [['path' => [['relation' => 'team', 'model' => 'Team']], 'end' => 'Team']]],
            'tenants' => ['Team' => ['relation' => 'members', 'column' => 'role', 'role' => 'owner']],
        ]);

        return FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Http/Controllers/ProjectController.php b/app/Http/Controllers/ProjectController.php',
            '--- a/app/Http/Controllers/ProjectController.php',
            '+++ b/app/Http/Controllers/ProjectController.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// Projects',
            '',
        ])]);
    }

    public function test_a_person_who_opened_another_teams_project_fails_the_checks_and_says_how(): void
    {
        $this->swaps = ['{"id":0,"owners":true,"control":{"status":200,"invalid":false,"writes":0},"swap":{"status":200,"invalid":false,"writes":0},"guest":302,"policy":false}'];
        $this->answer([]);
        $change = $this->projectChange();

        app(RequestVerification::class)->handle($change);

        $result = collect($change->verifications()->sole()->results)->firstWhere('name', 'Who may see and change records');
        $this->assertSame('failed', $result['outcome']);
        $this->assertStringContainsString('A signed-in person could see a project of another team: GET /projects/{project} answered 200.', $result['output']);

        // The swaps ran with their test's path, and the test and the script were taken out after.
        $commands = array_column($this->driver->executed, 'command');
        $this->assertContains([...self::SWAP, 'tests/Feature/SwapProbeTest.php'], $commands);
        $this->assertContains(['rm', '-f', 'tests/Feature/SwapProbeTest.php'], $commands);
        $this->assertContains(['rm', '-f', 'bindings.php'], $commands);
    }

    public function test_a_page_anyone_can_open_is_shared_on_purpose_and_passes(): void
    {
        $this->swaps = ['{"id":0,"owners":true,"control":{"status":200,"invalid":false,"writes":0},"swap":{"status":200,"invalid":false,"writes":0},"guest":200,"policy":null}'];
        $this->answer([]);
        $change = $this->projectChange();

        app(RequestVerification::class)->handle($change);

        $this->assertNull(collect($change->verifications()->sole()->results)->firstWhere('name', 'Who may see and change records'), 'nothing was refused or found, so there is nothing to say');
        $this->assertNotSame(VerificationStatus::Failed, $change->verifications()->sole()->status);
    }

    public function test_a_route_the_person_could_not_use_with_their_own_records_proves_nothing(): void
    {
        $this->swaps = ['{"id":0,"owners":true,"control":{"status":404,"invalid":false,"writes":0},"swap":{"status":200,"invalid":false,"writes":0},"guest":302,"policy":null}'];
        $this->answer([]);
        $change = $this->projectChange();

        app(RequestVerification::class)->handle($change);

        $this->assertNull(collect($change->verifications()->sole()->results)->firstWhere('name', 'Who may see and change records'));
        $this->assertNotSame(VerificationStatus::Failed, $change->verifications()->sole()->status);
    }

    /**
     * A change to the projects controller, whose list loads every project
     * of the person's team; the change added that line or another one.
     */
    protected function listChange(string $added): FeatureRequest
    {
        $load = '$projects = Project::where(\'team_id\', $request->user()->current_team_id)->get();';
        $this->bindings = "Booting.\n".json_encode([
            'user' => 'User',
            'routes' => [
                ['methods' => ['GET'], 'uri' => '/projects', 'name' => 'projects.index', 'domain' => null, 'controller' => 'App\Http\Controllers\ProjectController', 'action' => 'index', 'params' => [], 'loads' => [$load]],
            ],
            'owners' => ['Project' => [['path' => [['relation' => 'team', 'model' => 'Team', 'key' => 'team_id']], 'end' => 'Team']]],
            'tenants' => ['Team' => ['relation' => 'members', 'column' => 'role', 'role' => 'owner']],
        ]);

        return FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Http/Controllers/ProjectController.php b/app/Http/Controllers/ProjectController.php',
            '--- a/app/Http/Controllers/ProjectController.php',
            '+++ b/app/Http/Controllers/ProjectController.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+        '.($added === 'load' ? $load : '// Projects'),
            '',
        ])]);
    }

    public function test_a_list_the_change_loads_whole_fails_the_checks_and_says_how(): void
    {
        $this->swaps = ['{"id":0,"owners":true,"control":{"status":200,"invalid":false,"writes":0},"rows":60,"shown":60}'];
        $this->answer([]);
        $change = $this->listChange('load');

        app(RequestVerification::class)->handle($change);

        $result = collect($change->verifications()->sole()->results)->firstWhere('name', 'Long lists show a page at a time');
        $this->assertSame('failed', $result['outcome']);
        $this->assertStringContainsString('GET /projects sent all 60 projects at once ($projects = Project::where(\'team_id\', $request->user()->current_team_id)->get();), so the page gets slower with each one. Show a page at a time: paginate()', $result['output']);
        $this->assertSame(VerificationStatus::Failed, $change->verifications()->sole()->status);
        $this->assertStringContainsString('private const ROWS = 60;', (string) collect($this->driver->files)->first(fn (string $content, string $path) => str_ends_with($path, ':tests/Feature/SwapProbeTest.php')));
    }

    public function test_a_list_that_was_whole_before_the_change_or_shows_a_page_at_a_time_passes(): void
    {
        $this->swaps = ['{"id":0,"owners":true,"control":{"status":200,"invalid":false,"writes":0},"rows":60,"shown":60}'];
        $this->answer([]);
        $change = $this->listChange('other');

        app(RequestVerification::class)->handle($change);

        $result = collect($change->verifications()->sole()->results)->firstWhere('name', 'Long lists show a page at a time');
        $this->assertSame('passed', $result['outcome']);
        $this->assertStringStartsWith('Note, not a failure: GET /projects sent all 60 projects at once', $result['output']);
        $this->assertNotSame(VerificationStatus::Failed, $change->verifications()->sole()->status);

        $this->swaps = ['{"id":0,"owners":true,"control":{"status":200,"invalid":false,"writes":0},"rows":60,"shown":15}'];
        $paged = $this->listChange('load');

        app(RequestVerification::class)->handle($paged);

        $this->assertSame('Opened 1 lists with many records each; each showed a page at a time.', collect($paged->verifications()->sole()->results)->firstWhere('name', 'Long lists show a page at a time')['output']);
    }

    public function test_a_list_that_could_not_be_read_adds_no_check(): void
    {
        // A Blade page, not JSON or Inertia props.
        $this->swaps = ['{"id":0,"owners":true,"control":{"status":200,"invalid":false,"writes":0},"rows":60,"shown":null}'];
        $this->answer([]);
        $change = $this->listChange('load');

        app(RequestVerification::class)->handle($change);

        $this->assertNull(collect($change->verifications()->sole()->results)->firstWhere('name', 'Long lists show a page at a time'));
        $this->assertNotSame(VerificationStatus::Failed, $change->verifications()->sole()->status);
    }
}
