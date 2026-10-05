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

    /**
     * What the script that finds the app's teams prints.
     */
    protected string $teams = '{"tenants":[],"owned":{}}';

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
}
