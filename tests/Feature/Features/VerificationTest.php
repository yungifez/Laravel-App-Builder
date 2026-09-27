<?php

namespace Tests\Feature\Features;

use App\Actions\Features\RequestVerification;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\UsesAcceptanceSuite;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase, UsesAcceptanceSuite;

    protected const ACCEPTANCE_COMMAND = ['php', 'vendor/bin/phpunit', '--configuration', 'tests/Acceptance/phpunit.xml'];

    protected FakeWorkspaceDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->useAcceptanceSuite();

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [
                ['name' => 'Install', 'command' => ['composer', 'install'], 'timeout' => 600],
                ['name' => 'Key', 'command' => ['php', 'artisan', 'key:generate'], 'timeout' => 60],
            ],
            'builder.verification.checks' => [
                ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300],
                ['name' => 'Lint', 'command' => ['pint', '--test'], 'timeout' => 60],
            ],
            // Looked up in its own test below.
            'builder.verification.security.enabled' => false,
        ]);
    }

    public function test_known_security_problems_in_packages_are_advice_that_never_fails_the_change()
    {
        $composer = ['composer', 'audit', '--format=json'];
        $npm = ['npm', 'audit', '--json'];
        config(['builder.verification.security' => ['enabled' => true, 'steps' => [
            ['name' => 'PHP packages', 'report' => 'composer', 'command' => $composer, 'timeout' => 120],
            ['name' => 'JavaScript packages', 'report' => 'npm', 'command' => $npm, 'timeout' => 120],
        ]]]);
        $reports = [
            // One known problem, and a report exit code that says so.
            'composer' => [1, '{"advisories":{"guzzlehttp/psr7":[{"severity":"high"}]},"abandoned":[]}'],
            // The lookup itself failed: no report, so nothing is said.
            'npm' => [1, 'npm ERR! network'],
        ];
        $this->driver->onExec = function (string $workspace, array $command) use ($composer, $npm, &$reports) {
            [$exitCode, $output] = match ($command) {
                $composer => $reports['composer'],
                $npm => $reports['npm'],
                default => [0, 'ok'],
            };

            return new CommandResult(exitCode: $exitCode, output: $output, errorOutput: '', durationMs: 5);
        };

        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);
        app(RequestVerification::class)->handle($request);

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Passed, $verification->status);
        $this->assertSame(['failed', 'errored'], array_column(array_values(array_filter($verification->results, fn (array $result) => $result['stage'] === 'security')), 'outcome'));

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                // Never listed as a check that failed.
                ->where('verification.results', fn ($results) => collect($results)->every(fn (array $result) => $result['stage'] !== 'security'))
                ->where('proof', fn ($proof) => collect($proof)->contains('text', 'Some packages your app uses have known security problems. Ask me to update them.')));

        $reports['composer'] = [0, '{"advisories":[],"abandoned":[]}'];
        $reports['npm'] = [0, '{"metadata":{"vulnerabilities":{"moderate":2,"high":0,"critical":0}}}'];
        $clean = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);
        app(RequestVerification::class)->handle($clean);

        $this->actingAs($clean->project->owner)
            ->get(route('feature-requests.show', $clean))
            ->assertInertia(fn (Assert $page) => $page
                ->where('proof', fn ($proof) => collect($proof)->contains('text', 'No known security problems in the packages your app uses.')));
    }

    public function test_a_follow_up_is_verified_with_its_lineage_applied_and_the_protected_suite_run_last()
    {
        $parent = FeatureRequest::factory()->generated()->create(['patch' => 'PARENT PATCH']);
        $followUp = FeatureRequest::factory()->generated()->for($parent->project)->create([
            'parent_id' => $parent->id,
            'target_step' => 'permission',
            'patch' => 'FOLLOW-UP PATCH',
            'acceptance' => ['Invitations/ContractTest.php'],
        ]);

        $this->actingAs($parent->project->owner)
            ->from(route('projects.show', ['project' => $followUp->project_id, 'change' => $followUp->id]))
            ->post(route('feature-requests.verifications.store', $followUp))
            ->assertRedirect(route('projects.show', ['project' => $followUp->project_id, 'change' => $followUp->id]));

        $verification = $followUp->verifications()->sole();
        $this->assertSame(VerificationStatus::Passed, $verification->status);

        $workspaceId = $this->driver->copies[0]['workspace'];
        $this->assertSame($parent->project->source_path, $this->driver->copies[0]['source']);
        $this->assertSame('PARENT PATCH', $this->driver->files["{$workspaceId}:.patches-to-apply/01.patch"]);
        $this->assertSame('FOLLOW-UP PATCH', $this->driver->files["{$workspaceId}:.patches-to-apply/02.patch"]);

        $this->assertSame('<phpunit>platform runner</phpunit>', $this->driver->files["{$workspaceId}:tests/Acceptance/phpunit.xml"]);
        $this->assertSame('<?php // platform helper', $this->driver->files["{$workspaceId}:tests/Acceptance/Support/Helper.php"]);
        $this->assertSame('<?php // platform contract test', $this->driver->files["{$workspaceId}:tests/Acceptance/Invitations/ContractTest.php"]);

        $commands = array_column($this->driver->executed, 'command');
        $this->assertSame(['rm', '-rf', 'tests/Acceptance'], $commands[count($commands) - 2]);
        $this->assertSame(self::ACCEPTANCE_COMMAND, end($commands));

        $this->assertSame(
            ["Apply change #{$parent->id}", "Apply change #{$followUp->id}", 'Install', 'Key', 'Tests', 'Lint', 'Protected acceptance tests'],
            array_column($verification->results, 'name'),
        );
        $this->assertSame(array_fill(0, 7, 'passed'), array_column($verification->results, 'outcome'));
        $this->assertSame([$workspaceId], $this->driver->destroyed);
    }

    public function test_a_finished_verification_is_carried_back_to_the_run_that_asked_for_it()
    {
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);
        $run = Run::factory()->for($request)->create(['status' => RunStatus::Verifying]);

        app(RequestVerification::class)->handle($request, $run);

        $this->assertSame(VerificationStatus::Passed, $run->verifications()->sole()->status);
        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
    }

    public function test_a_failing_protected_suite_fails_the_verification_even_when_every_check_passes()
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command === self::ACCEPTANCE_COMMAND ? 1 : 0,
            output: $command === self::ACCEPTANCE_COMMAND ? 'FAILURES! Tests: 12, Failures: 1.' : 'ok',
            errorOutput: '',
            durationMs: 10,
        );
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $acceptance = collect($verification->results)->firstWhere('stage', 'acceptance');
        $this->assertSame('failed', $acceptance['outcome']);
        $this->assertStringContainsString('Failures: 1', $acceptance['output']);
    }

    public function test_the_tests_the_suite_ran_are_recorded_from_its_report()
    {
        config(['builder.verification.checks' => [
            ['name' => 'Tests', 'command' => ['php', 'artisan', 'test', '--log-junit=storage/logs/junit.xml'], 'timeout' => 300, 'report' => 'storage/logs/junit.xml'],
        ]]);
        $this->driver->onExec = function (string $workspace, array $command) {
            if ($command[0] === 'rm') {
                unset($this->driver->files["{$workspace}:storage/logs/junit.xml"]);
            }

            if (($command[2] ?? null) === 'test') {
                $this->driver->files["{$workspace}:storage/logs/junit.xml"] = '<testsuites><testcase name="test_teams_have_a_description" file="/workspace/tests/Feature/TeamTest.php"/><testcase name="test_later" file="/workspace/tests/Feature/TeamTest.php"><skipped/></testcase></testsuites>';
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $featureRequest = FeatureRequest::factory()->generated()->create(['patch' => 'PATCH']);

        app(RequestVerification::class)->handle($featureRequest);

        $tests = collect($featureRequest->verifications()->sole()->results)->firstWhere('name', 'Tests');
        $this->assertSame([
            ['file' => '/workspace/tests/Feature/TeamTest.php', 'name' => 'test_teams_have_a_description', 'outcome' => 'passed'],
            ['file' => '/workspace/tests/Feature/TeamTest.php', 'name' => 'test_later', 'outcome' => 'skipped'],
        ], $tests['tests']);
    }

    public function test_a_suite_that_leaves_no_report_records_no_tests_run()
    {
        config(['builder.verification.checks' => [
            ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300, 'report' => 'storage/logs/junit.xml'],
        ]]);
        $featureRequest = FeatureRequest::factory()->generated()->create(['patch' => 'PATCH']);
        $this->driver->files['fake-1:storage/logs/junit.xml'] = '<testsuites><testcase name="stale" file="tests/Feature/OldTest.php"/></testsuites>';
        $this->driver->onExec = function (string $workspace, array $command) {
            if ($command[0] === 'rm') {
                unset($this->driver->files["{$workspace}:storage/logs/junit.xml"]);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };

        app(RequestVerification::class)->handle($featureRequest);

        $this->assertSame([], collect($featureRequest->verifications()->sole()->results)->firstWhere('name', 'Tests')['tests']);
    }

    public function test_a_change_without_protected_tests_is_unverified_not_passed()
    {
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => null]);

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Unverified, $verification->status);
        $this->assertSame('not_applicable', collect($verification->results)->firstWhere('stage', 'acceptance')['outcome']);
        $this->assertNotContains(self::ACCEPTANCE_COMMAND, array_column($this->driver->executed, 'command'));
    }

    public function test_a_missing_protected_test_file_makes_the_verification_error()
    {
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/Missing.php']]);

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Errored, $verification->status);
        $acceptance = collect($verification->results)->firstWhere('stage', 'acceptance');
        $this->assertSame('errored', $acceptance['outcome']);
        $this->assertStringContainsString('Invitations/Missing.php', $acceptance['output']);
    }

    public function test_every_check_runs_and_a_failing_one_fails_the_verification()
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command === ['php', 'artisan', 'test'] ? 1 : 0,
            output: $command === ['php', 'artisan', 'test'] ? "\e[31;1m1 failed\e[39;22m" : 'ok',
            errorOutput: '',
            durationMs: 10,
        );
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $checks = collect($verification->results)->where('stage', 'checks')->values();
        $this->assertSame(['Tests', 'Lint'], $checks->pluck('name')->all());
        $this->assertSame(['failed', 'passed'], $checks->pluck('outcome')->all());
        $this->assertSame('1 failed', $checks[0]['output']);
        $this->assertSame('passed', collect($verification->results)->firstWhere('stage', 'acceptance')['outcome']);
    }

    public function test_a_timed_out_check_is_an_error_not_a_failure()
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command === ['pint', '--test'] ? 124 : 0,
            output: '',
            errorOutput: '',
            durationMs: 60000,
            timedOut: $command === ['pint', '--test'],
        );
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $lint = collect($request->verifications()->sole()->results)->firstWhere('name', 'Lint');
        $this->assertSame('errored', $lint['outcome']);
        $this->assertTrue($lint['timed_out']);
    }

    public function test_a_failing_setup_step_skips_everything_after_it()
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command === ['composer', 'install'] ? 2 : 0,
            output: '',
            errorOutput: 'network down',
            durationMs: 10,
        );
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Errored, $verification->status);
        $this->assertSame('A setup step failed, so the checks did not run.', $verification->error);
        $this->assertSame(
            ['Install' => 'failed', 'Key' => 'skipped', 'Tests' => 'skipped', 'Lint' => 'skipped', 'Protected acceptance tests' => 'skipped'],
            collect($verification->results)->slice(1)->pluck('outcome', 'name')->all(),
        );
        $this->assertNotContains(['php', 'artisan', 'test'], array_column($this->driver->executed, 'command'));
        $this->assertCount(1, $this->driver->destroyed);
    }

    public function test_a_change_that_does_not_apply_is_reported()
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command[0] === 'git' ? 1 : 0,
            output: '',
            errorOutput: 'patch does not apply',
            durationMs: 10,
        );
        $request = FeatureRequest::factory()->generated()->create();

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Errored, $verification->status);
        $this->assertSame('The change does not apply to the project.', $verification->error);
        $this->assertSame('patch does not apply', $verification->results[0]['output']);
    }

    public function test_only_generated_changes_without_a_run_in_progress_can_be_verified()
    {
        $generating = FeatureRequest::factory()->create();
        $busy = FeatureRequest::factory()->generated()->create();
        Verification::factory()->for($busy)->create(['status' => VerificationStatus::Running]);

        $this->actingAs($generating->project->owner)
            ->post(route('feature-requests.verifications.store', $generating))
            ->assertSessionHasErrors('verification');

        $this->actingAs($busy->project->owner)
            ->post(route('feature-requests.verifications.store', $busy))
            ->assertSessionHasErrors('verification');

        $this->assertSame(0, $generating->verifications()->count());
        $this->assertSame(1, $busy->verifications()->count());
    }

    public function test_other_users_cannot_verify_a_request()
    {
        $request = FeatureRequest::factory()->generated()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('feature-requests.verifications.store', $request))
            ->assertForbidden();
    }

    public function test_the_page_shows_the_latest_verification()
    {
        $request = FeatureRequest::factory()->generated()->create();
        Verification::factory()->for($request)->create(['status' => VerificationStatus::Failed]);
        Verification::factory()->for($request)->create([
            'status' => VerificationStatus::Passed,
            'results' => [['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 5, 'output' => 'OK']],
        ]);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('verification.status', 'passed')
                ->where('verification.results.0.outcome', 'passed'));
    }

    public function test_a_change_to_a_screen_has_its_pages_measured_once_the_checks_pass()
    {
        $measure = ['sh', '-c', 'measure the screens'];
        config(['builder.verification.screens' => ['enabled' => true, 'command' => $measure, 'timeout' => 600, 'report' => 'screens.json', 'shots_disk' => 'local', 'shots_max' => 3]]);
        $this->driver->onExec = function (string $workspace, array $command) use ($measure) {
            if ($command === $measure) {
                $this->driver->files["{$workspace}:screens.json"] = '{"pages":[{"path":"/teams","screen":"Teams","widths":[]}],"signed_in":true}';
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $screen = FeatureRequest::factory()->generated()->create(['patch' => "diff --git a/resources/js/pages/Teams.vue b/resources/js/pages/Teams.vue\n+++ b/resources/js/pages/Teams.vue\n@@ -1 +1,2 @@\n+<p>Teams</p>"]);
        $code = FeatureRequest::factory()->generated()->create(['patch' => "diff --git a/app/Models/Team.php b/app/Models/Team.php\n+++ b/app/Models/Team.php\n@@ -1 +1,2 @@\n+// Teams"]);

        app(RequestVerification::class)->handle($screen);
        app(RequestVerification::class)->handle($code);

        $this->assertSame(['pages' => [['path' => '/teams', 'screen' => 'Teams', 'widths' => []]], 'signed_in' => true, 'shots' => []], $screen->verifications()->sole()->screens);
        // A change with no screen is not measured, and the measuring is never one of the checks.
        $this->assertNull($code->verifications()->sole()->screens);
        $this->assertSame(1, collect($this->driver->executed)->where('command', $measure)->count());
        $this->assertNotContains('Screens', array_column($screen->verifications()->sole()->results, 'name'));
    }

    public function test_screens_are_not_measured_when_the_checks_fail_or_the_tool_cannot_run()
    {
        $measure = ['sh', '-c', 'measure the screens'];
        config(['builder.verification.screens' => ['enabled' => true, 'command' => $measure, 'timeout' => 600, 'report' => 'screens.json', 'shots_disk' => 'local', 'shots_max' => 3]]);
        $patch = "diff --git a/resources/js/pages/Teams.vue b/resources/js/pages/Teams.vue\n+++ b/resources/js/pages/Teams.vue\n@@ -1 +1,2 @@\n+<p>Teams</p>";
        $failing = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: $command === ['pint', '--test'] ? 1 : 0, output: 'ok', errorOutput: '', durationMs: 5);

        app(RequestVerification::class)->handle($failing);

        $this->assertSame(VerificationStatus::Failed, $failing->verifications()->sole()->status);
        $this->assertSame(0, collect($this->driver->executed)->where('command', $measure)->count());

        // Where the measuring tool is not installed its command fails, and nothing is kept.
        $missing = FeatureRequest::factory()->generated()->create(['patch' => $patch, 'acceptance' => ['Invitations/ContractTest.php']]);
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: $command === $measure ? 1 : 0, output: '', errorOutput: 'test: not found', durationMs: 5);

        app(RequestVerification::class)->handle($missing);

        $this->assertSame(VerificationStatus::Passed, $missing->verifications()->sole()->status);
        $this->assertNull($missing->verifications()->sole()->screens);
    }

    public function test_pictures_of_the_touched_screens_are_kept_after_the_workspace_is_gone()
    {
        Storage::fake('local');
        $measure = ['sh', '-c', 'measure the screens'];
        config(['builder.verification.screens' => ['enabled' => true, 'command' => $measure, 'timeout' => 600, 'report' => 'screens.json', 'shots_disk' => 'local', 'shots_max' => 3]]);
        $this->driver->onExec = function (string $workspace, array $command) use ($measure) {
            if ($command === $measure) {
                $this->driver->files["{$workspace}:screens.json"] = json_encode(['pages' => [['path' => '/teams', 'screen' => 'teams/Index', 'widths' => [], 'shots' => [
                    ['width' => 390, 'file' => 'storage/logs/screens/shots/1-390.jpg'],
                    ['width' => 1280, 'file' => 'storage/logs/screens/shots/2-1280.jpg'],
                    // Only JPEG pictures in the tool's own folder are kept.
                    ['width' => 820, 'file' => '.env'],
                    ['width' => 820, 'file' => 'storage/logs/screens/shots/3-820.jpg'],
                ]]]]);
                $this->driver->files["{$workspace}:storage/logs/screens/shots/1-390.jpg"] = "\xFF\xD8phone";
                $this->driver->files["{$workspace}:storage/logs/screens/shots/2-1280.jpg"] = "\xFF\xD8computer";
                $this->driver->files["{$workspace}:.env"] = "\xFF\xD8APP_KEY=secret";
                $this->driver->files["{$workspace}:storage/logs/screens/shots/3-820.jpg"] = '<html>not a picture</html>';
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $featureRequest = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/resources/js/pages/teams/Index.vue b/resources/js/pages/teams/Index.vue',
            '+++ b/resources/js/pages/teams/Index.vue',
            '@@ -1 +1,2 @@',
            '+<p>Teams</p>',
            'diff --git a/resources/js/components/Old.vue b/resources/js/components/Old.vue',
            'deleted file mode 100644',
        ])]);

        app(RequestVerification::class)->handle($featureRequest);

        $verification = $featureRequest->verifications()->sole();
        // The check is told which screens to take pictures of.
        $this->assertContains(['SCREEN_CHECK_SHOOT' => 'teams/Index'], $this->driver->environments);
        $this->assertSame([
            ['screen' => 'teams/Index', 'width' => 390, 'path' => "screen-shots/{$verification->id}/1-390.jpg"],
            ['screen' => 'teams/Index', 'width' => 1280, 'path' => "screen-shots/{$verification->id}/2-1280.jpg"],
        ], $verification->screens['shots']);
        Storage::disk('local')->assertExists("screen-shots/{$verification->id}/2-1280.jpg");
        $this->assertSame(["screen-shots/{$verification->id}/1-390.jpg", "screen-shots/{$verification->id}/2-1280.jpg"], Storage::disk('local')->allFiles("screen-shots/{$verification->id}"));

        $this->actingAs($featureRequest->project->owner)
            ->get(route('verifications.shots.show', [$verification, 1]))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'");
        $this->actingAs($featureRequest->project->owner)->get(route('verifications.shots.show', [$verification, 5]))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('verifications.shots.show', [$verification, 0]))->assertForbidden();
    }
}
