<?php

namespace Tests\Feature\Features;

use App\Actions\Context\ReadProjectContext;
use App\Actions\Features\RequestVerification;
use App\Context\Capability;
use App\Context\ProjectContext;
use App\Enums\ChecksStoppedBecause;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Features\ArchPresets;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
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

        // One lookup was clean and the other could not run: the clean line
        // does not speak for the packages nobody looked at.
        $reports['npm'] = [1, 'npm error code ENOLOCK'];
        $partly = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);
        app(RequestVerification::class)->handle($partly);

        $this->actingAs($partly->project->owner)
            ->get(route('feature-requests.show', $partly))
            ->assertInertia(fn (Assert $page) => $page
                ->where('proof', fn ($proof) => collect($proof)->contains('text', 'No known security problems in the packages I could check.')
                    && ! collect($proof)->contains('text', 'No known security problems in the packages your app uses.')));

        // An app with no JavaScript packages has nothing there to look up,
        // so the clean PHP lookup speaks for every package it uses.
        config(['builder.verification.security.steps.1.needs' => 'package.json']);
        $reports['npm'] = [1, 'npm error code ENOLOCK'];
        $exec = $this->driver->onExec;
        $this->driver->onExec = fn (string $workspace, array $command) => $command === ['test', '-e', 'package.json']
            ? new CommandResult(exitCode: 1, output: '', errorOutput: '', durationMs: 1)
            : $exec($workspace, $command);
        $phpOnly = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);
        app(RequestVerification::class)->handle($phpOnly);

        $this->assertSame(['passed', 'not_applicable'], array_column(array_values(array_filter($phpOnly->verifications()->sole()->results, fn (array $result) => $result['stage'] === 'security')), 'outcome'));
        $this->actingAs($phpOnly->project->owner)
            ->get(route('feature-requests.show', $phpOnly))
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
            ->from(route('projects.show', ['project' => $followUp->project, 'change' => $followUp->uuid]))
            ->post(route('feature-requests.verifications.store', $followUp))
            ->assertRedirect(route('projects.show', ['project' => $followUp->project, 'change' => $followUp->uuid]));

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

    public function test_a_step_the_app_has_no_use_for_does_not_apply_to_it()
    {
        config([
            'builder.verification.setup' => [
                ['name' => 'Install', 'command' => ['composer', 'install'], 'timeout' => 600],
                ['name' => 'Build the screens', 'command' => ['npm', 'run', 'build'], 'timeout' => 600, 'needs' => 'package.json'],
            ],
            'builder.verification.checks' => [
                ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300],
                ['name' => 'TypeScript', 'command' => ['npm', 'run', 'types:check'], 'timeout' => 300, 'needs' => 'tsconfig.json'],
            ],
        ]);
        // A Livewire app with no frontend build of its own.
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: $command[0] === 'test' ? 1 : 0, output: 'ok', errorOutput: '', durationMs: 5);
        $featureRequest = FeatureRequest::factory()->generated()->create(['patch' => 'PATCH']);

        app(RequestVerification::class)->handle($featureRequest);

        $results = collect($featureRequest->verifications()->sole()->results)->keyBy('name');
        $this->assertSame('passed', $results['Tests']['outcome']);
        $this->assertSame(['not_applicable', 'The app has no package.json, so this does not apply to it.'], [$results['Build the screens']['outcome'], $results['Build the screens']['output']]);
        $this->assertSame('not_applicable', $results['TypeScript']['outcome']);
        $this->assertNotContains(['npm', 'run', 'build'], array_column($this->driver->executed, 'command'));
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
        // The change did not touch what the app installs: making it again cannot help.
        $this->assertSame(ChecksStoppedBecause::Setup, $verification->stopped_because);
        $this->assertSame(ChecksStoppedBecause::Setup->message(), $verification->error);
        $this->assertSame(
            ['Install' => 'failed', 'Key' => 'skipped', 'Tests' => 'skipped', 'Lint' => 'skipped', 'Protected acceptance tests' => 'skipped'],
            collect($verification->results)->slice(1)->pluck('outcome', 'name')->all(),
        );
        $this->assertNotContains(['php', 'artisan', 'test'], array_column($this->driver->executed, 'command'));
        $this->assertCount(1, $this->driver->destroyed);
    }

    public function test_a_change_that_edits_a_file_the_checks_depend_on_is_sent_back_before_anything_runs()
    {
        $request = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/phpunit.xml b/phpunit.xml',
            '--- a/phpunit.xml',
            '+++ b/phpunit.xml',
            '@@ -1 +1 @@',
            '-<phpunit>',
            '+<phpunit stopOnFailure="false">',
            '',
        ])]);

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $guard = collect($verification->results)->firstWhere('name', 'Files the checks depend on');
        $this->assertSame('failed', $guard['outcome']);
        $this->assertStringContainsString('phpunit.xml', $guard['output']);
        // Nothing the change could have bent was run.
        $this->assertFalse(collect($this->driver->executed)->contains(fn (array $run) => $run['command'][0] === 'composer'));
        $this->assertSame(['skipped'], collect($verification->results)->where('stage', 'checks')->pluck('outcome')->unique()->values()->all());
    }

    public function test_a_change_to_the_scripts_in_a_package_manifest_is_sent_back_but_other_manifest_changes_are_not()
    {
        // The app had no package.json; the change adds one, with or
        // without scripts.
        $writes = [
            'scripts' => json_encode(['scripts' => ['build' => 'true']]),
            'require' => json_encode(['devDependencies' => ['vite' => '^7.0']]),
        ];

        foreach ($writes as $case => $after) {
            $this->driver->onExec = function (string $id, array $command) use ($after) {
                if ($command[0] === 'git' && $command[1] === 'apply') {
                    $this->driver->files["{$id}:package.json"] = $after;
                }

                return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
            };
            $request = FeatureRequest::factory()->generated()->create();

            $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

            $guard = collect($request->verifications()->sole()->results)->firstWhere('name', 'Files the checks depend on');

            if ($case === 'scripts') {
                $this->assertStringContainsString('the scripts in package.json', $guard['output']);
            } else {
                $this->assertNull($guard);
            }
        }
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
        $this->assertSame(ChecksStoppedBecause::DoesNotApply, $verification->stopped_because);
        $this->assertSame(ChecksStoppedBecause::DoesNotApply->message(), $verification->error);
        $this->assertSame('patch does not apply', $verification->results[0]['output']);
    }

    public function test_only_generated_changes_without_a_run_in_progress_can_be_verified()
    {
        $generating = FeatureRequest::factory()->create();
        $busy = FeatureRequest::factory()->generated()->create();
        Verification::factory()->for($busy)->create(['status' => VerificationStatus::Running]);

        $this->actingAs($generating->project->owner)
            ->post(route('feature-requests.verifications.store', $generating))
            ->assertSessionHasErrors(['verification' => 'There is no change to check yet.']);

        $this->actingAs($busy->project->owner)
            ->post(route('feature-requests.verifications.store', $busy))
            ->assertSessionHasErrors(['verification' => 'The checks are already running.']);

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

    public function test_the_page_names_the_checks_the_change_made_fail()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $result = fn (string $name, string $outcome, array $extra = []) => ['name' => $name, 'stage' => 'checks', 'outcome' => $outcome, 'exit_code' => $outcome === 'passed' ? 0 : 1, 'timed_out' => false, 'duration_ms' => 5, 'output' => '', ...$extra];
        $verification = Verification::factory()->for($request)->create([
            'status' => VerificationStatus::Failed,
            'results' => [
                $result('Tests', 'passed'),
                $result('Static analysis', 'failed', ['at_start' => 'passed']),
                // It failed the same way before the change: not the change's.
                $result('TypeScript', 'failed', ['at_start' => 'failed', 'new_problems' => []]),
            ],
        ]);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('verification.failed', 'Reading the code for mistakes failed'));

        $verification->update(['results' => [$result('Tests', 'failed'), $result('Static analysis', 'failed'), $result('Build the screens', 'failed', ['stage' => 'setup'])]]);

        $this->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('verification.failed', 'Your app\'s tests and 2 more failed'));
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

    public function test_the_php_a_change_touched_is_read_for_shortcuts_and_what_is_found_is_kept()
    {
        $scan = ['sh', '-c', 'scan for shortcuts'];
        config(['builder.verification.shortcuts' => ['enabled' => true, 'command' => $scan, 'timeout' => 120, 'report' => 'shortcuts.json']]);
        $this->driver->onExec = function (string $workspace, array $command) use ($scan) {
            if (array_slice($command, 0, 3) === $scan) {
                $this->driver->files["{$workspace}:shortcuts.json"] = json_encode(['schema' => 1, 'tool' => 'sloppy', 'findings' => [
                    ['rule' => 'SL107', 'file' => 'app/Models/Team.php', 'line' => 2, 'message' => 'Swallowed.'],
                ]]);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $code = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Models/Team.php b/app/Models/Team.php',
            '+++ b/app/Models/Team.php',
            '@@ -1 +1,2 @@',
            '+// Teams',
            'diff --git a/tests/Feature/TeamTest.php b/tests/Feature/TeamTest.php',
            '+++ b/tests/Feature/TeamTest.php',
            '@@ -1 +1,2 @@',
            '+// Test',
        ])]);
        $screen = FeatureRequest::factory()->generated()->create(['patch' => "diff --git a/resources/js/pages/Teams.vue b/resources/js/pages/Teams.vue\n+++ b/resources/js/pages/Teams.vue\n@@ -1 +1,2 @@\n+<p>Teams</p>"]);

        app(RequestVerification::class)->handle($code);
        app(RequestVerification::class)->handle($screen);

        // Only the app's own PHP files are read, and the reading is never one of the checks.
        $this->assertContains([...$scan, '--path=app/Models/Team.php'], array_column($this->driver->executed, 'command'));
        $this->assertSame([['rule' => 'SL107', 'path' => 'app/Models/Team.php', 'line' => 2]], $code->verifications()->sole()->shortcuts);
        $this->assertNotContains('Shortcuts', array_column($code->verifications()->sole()->results, 'name'));
        $this->assertNull($screen->verifications()->sole()->shortcuts);
        $this->assertSame(1, collect($this->driver->executed)->filter(fn (array $run) => array_slice($run['command'], 0, 3) === $scan)->count());

        // Where the analyser is not installed its command fails, and nothing is kept.
        $missing = FeatureRequest::factory()->generated()->create(['patch' => $code->patch]);
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: array_slice($command, 0, 3) === $scan ? 1 : 0, output: '', errorOutput: '', durationMs: 5);

        app(RequestVerification::class)->handle($missing);

        $this->assertNull($missing->verifications()->sole()->shortcuts);
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

    public function test_format_checks_read_only_the_files_the_change_added_or_modified()
    {
        config(['builder.verification.checks' => [
            ['name' => 'Lint', 'command' => ['pint', '--test'], 'timeout' => 60, 'files' => ['php']],
            ['name' => 'Frontend lint', 'command' => ['vp', 'check'], 'timeout' => 60, 'files' => ['vue']],
        ]]);
        $request = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Models/Team.php b/app/Models/Team.php',
            '--- a/app/Models/Team.php',
            '+++ b/app/Models/Team.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// A team',
            'diff --git a/app/Old.php b/app/Old.php',
            'deleted file mode 100644',
            '--- a/app/Old.php',
            '+++ /dev/null',
            '@@ -1 +0,0 @@',
            '-<?php',
            'diff --git a/.product-notes/teams.md b/.product-notes/teams.md',
            '--- a/.product-notes/teams.md',
            '+++ b/.product-notes/teams.md',
            '@@ -1 +1,2 @@',
            ' # Teams',
            '+Teams have a description.',
            '',
        ])]);

        app(RequestVerification::class)->handle($request);

        $commands = array_column($this->driver->executed, 'command');
        $this->assertContains(['pint', '--test', 'app/Models/Team.php'], $commands);
        $this->assertNotContains(['vp', 'check'], $commands);
        $this->assertSame(
            ['Lint' => 'passed', 'Frontend lint' => 'not_applicable'],
            collect($request->verifications()->sole()->results)->where('stage', 'checks')->pluck('outcome', 'name')->all(),
        );
    }

    public function test_a_check_that_already_failed_before_the_change_keeps_only_its_new_problems()
    {
        $atStart = false;
        $this->driver->onExec = function (string $id, array $command) use (&$atStart) {
            if ($command[0] === 'git') {
                $atStart = in_array('--reverse', $command, true);
            }

            return match (true) {
                $command === ['php', 'artisan', 'test'] => new CommandResult(exitCode: 1, output: $atStart ? "FAIL old problem on line 12\nTests: 1 failed" : "FAIL old problem on line 14\nFAIL teams have a description\nTests: 2 failed", errorOutput: '', durationMs: 10),
                default => new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5),
            };
        };
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);

        app(RequestVerification::class)->handle($request);

        $verification = $request->verifications()->sole();
        $tests = collect($verification->results)->firstWhere('name', 'Tests');
        $this->assertSame('failed', $tests['outcome']);
        $this->assertSame('failed', $tests['at_start']);
        $this->assertSame(['FAIL teams have a description'], $tests['new_problems']);
        $this->assertArrayNotHasKey('at_start', collect($verification->results)->firstWhere('name', 'Lint'));
        $this->assertSame(VerificationStatus::Failed, $verification->status);

        // The change is put back before the protected tests run on it.
        $applies = array_values(array_filter(array_column($this->driver->executed, 'command'), fn (array $command) => $command[0] === 'git'));
        $this->assertContains('--reverse', end($applies) === false ? [] : $applies[count($applies) - 2]);
        $this->assertNotContains('--reverse', end($applies));
        $this->assertSame('passed', collect($verification->results)->firstWhere('stage', 'acceptance')['outcome']);
    }

    #[DataProvider('diagnosticOutputs')]
    public function test_diagnostic_comparison_preserves_additional_errors(string $before, string $after, array $expected)
    {
        config(['builder.verification.checks' => [
            ['name' => 'Static analysis', 'command' => ['analyse'], 'timeout' => 60],
        ]]);
        $atStart = false;
        $this->driver->onExec = function (string $workspace, array $command) use (&$atStart, $before, $after) {
            if ($command[0] === 'git') {
                $atStart = in_array('--reverse', $command, true);
            }

            return $command === ['analyse']
                ? new CommandResult(exitCode: 1, output: $atStart ? $before : $after, errorOutput: '', durationMs: 10)
                : new CommandResult(exitCode: 0, output: '', errorOutput: '', durationMs: 5);
        };
        $request = FeatureRequest::factory()->generated()->create();

        app(RequestVerification::class)->handle($request);

        $result = collect($request->verifications()->sole()->results)->firstWhere('name', 'Static analysis');
        $this->assertSame('failed', $result['at_start']);
        $this->assertSame($expected, $result['new_problems']);
    }

    public static function diagnosticOutputs(): array
    {
        return [
            'additional occurrence' => [
                "10  Call to undefined method Foo::missing().\n20  Call to undefined method Foo::missing().\n[ERROR] Found 2 errors",
                "12  Call to undefined method Foo::missing().\n22  Call to undefined method Foo::missing().\n30  Call to undefined method Foo::missing().\n[ERROR] Found 3 errors",
                ['30  Call to undefined method Foo::missing().'],
            ],
            'numeric types differ' => ['10  Expected 200, got 400.', '12  Expected 200, got 500.', ['12  Expected 200, got 500.']],
            'error codes differ' => ['app.ts(10,3): error TS2322: Broken.', 'app.ts(12,4): error TS2344: Broken.', ['app.ts(12,4): error TS2344: Broken.']],
            'locations move' => ["app.ts(10,3): error TS2322: Broken.\napp/Foo.php:10: Broken on line 12", "app.ts(12,4): error TS2322: Broken.\napp/Foo.php:12: Broken on line 14", []],
        ];
    }

    #[DataProvider('reportsWithoutFailures')]
    public function test_failed_suites_without_reported_failures_are_not_dismissed_as_existing_problems(?string $report, bool $onBaseline)
    {
        Queue::fake([ExecuteRun::class]);
        config(['builder.verification.checks' => [
            ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300, 'report' => 'storage/logs/junit.xml'],
        ]]);
        $atStart = false;
        $this->driver->onExec = function (string $workspace, array $command) use (&$atStart, $report, $onBaseline) {
            if ($command[0] === 'git') {
                $atStart = in_array('--reverse', $command, true);
            }

            if ($command === ['rm', '-f', 'storage/logs/junit.xml']) {
                unset($this->driver->files["{$workspace}:storage/logs/junit.xml"]);
            }

            if ($command === ['php', 'artisan', 'test']) {
                $xml = $atStart === $onBaseline ? $report : '<testsuites><testcase name="old failure" file="tests/OldTest.php"><failure/></testcase></testsuites>';

                if ($xml !== null) {
                    $this->driver->files["{$workspace}:storage/logs/junit.xml"] = $xml;
                }

                return new CommandResult(exitCode: 1, output: 'The test command failed.', errorOutput: '', durationMs: 10);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $request = FeatureRequest::factory()->generated()->create();
        $run = Run::factory()->for($request)->create(['status' => RunStatus::Verifying, 'driver' => 'worker']);

        app(RequestVerification::class)->handle($request, $run);

        $verification = $request->verifications()->sole();
        $result = collect($verification->results)->firstWhere('name', 'Tests');
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $this->assertArrayNotHasKey('at_start', $result);
        $this->assertArrayNotHasKey('new_problems', $result);
        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $this->assertSame(1, $run->repairs);
        $this->assertSame(["Tests failed:\nThe test command failed."], $run->feedback['details']);
        Queue::assertPushed(ExecuteRun::class, 1);
        $this->assertCount(1, $this->driver->destroyed);
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function reportsWithoutFailures(): array
    {
        $cases = [];

        foreach (['missing' => null, 'malformed' => 'not xml', 'empty' => '<testsuites/>', 'passing' => '<testsuites><testcase name="passes" file="tests/OldTest.php"/></testsuites>'] as $name => $report) {
            $cases["{$name} change report"] = [$report, false];
            $cases["{$name} baseline report"] = [$report, true];
        }

        return $cases;
    }

    public function test_matching_reported_test_failures_still_go_on_to_review()
    {
        Queue::fake([ExecuteRun::class]);
        config(['builder.verification.checks' => [
            ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300, 'report' => 'storage/logs/junit.xml'],
        ]]);
        $this->driver->onExec = function (string $workspace, array $command) {
            if ($command === ['rm', '-f', 'storage/logs/junit.xml']) {
                unset($this->driver->files["{$workspace}:storage/logs/junit.xml"]);
            }

            if ($command === ['php', 'artisan', 'test']) {
                $this->driver->files["{$workspace}:storage/logs/junit.xml"] = '<testsuites><testcase name="old failure" file="tests/OldTest.php"><failure/></testcase></testsuites>';

                return new CommandResult(exitCode: 1, output: 'Old test failure.', errorOutput: '', durationMs: 10);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $request = FeatureRequest::factory()->generated()->create();
        $run = Run::factory()->for($request)->create(['status' => RunStatus::Verifying, 'driver' => 'worker']);

        app(RequestVerification::class)->handle($request, $run);

        $result = collect($request->verifications()->sole()->results)->firstWhere('name', 'Tests');
        $this->assertSame('failed', $result['at_start']);
        $this->assertSame([], $result['new_problems']);
        $this->assertSame(RunStatus::Reviewing, $run->refresh()->status);
        $this->assertSame(0, $run->repairs);
        Queue::assertPushed(ExecuteRun::class, 1);
    }

    public function test_a_change_to_the_packages_is_judged_on_its_own_result()
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command === ['php', 'artisan', 'test'] ? 1 : 0,
            output: 'FAIL',
            errorOutput: '',
            durationMs: 10,
        );
        $request = FeatureRequest::factory()->generated()->create(['patch' => "diff --git a/composer.json b/composer.json\n--- a/composer.json\n+++ b/composer.json\n@@ -1 +1,2 @@\n {\n+\n"]);

        app(RequestVerification::class)->handle($request);

        $this->assertArrayNotHasKey('at_start', collect($request->verifications()->sole()->results)->firstWhere('name', 'Tests'));
        $this->assertEmpty(array_filter(array_column($this->driver->executed, 'command'), fn (array $command) => in_array('--reverse', $command, true)));
    }

    public function test_a_command_no_runner_took_stops_the_checks_as_our_fault()
    {
        $this->driver->onExec = fn (string $id, array $command) => $command === ['php', 'artisan', 'test']
            ? new CommandResult(exitCode: 124, output: '', errorOutput: 'No runner took the command.', durationMs: 0, timedOut: true, lost: true)
            : new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        $request = FeatureRequest::factory()->generated()->create(['acceptance' => ['Invitations/ContractTest.php']]);

        app(RequestVerification::class)->handle($request);

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Errored, $verification->status);
        $this->assertTrue($verification->interrupted);
        $this->assertSame('The checks stopped because of a problem on our side. This is our fault.', $verification->error);
        $this->assertNotContains(['pint', '--test'], array_column($this->driver->executed, 'command'));
        $this->assertCount(1, $this->driver->destroyed);
    }

    /**
     * A change to the team model that adds one test file and changes
     * another the app already had.
     */
    protected function changeWithTests(): string
    {
        return implode("\n", [
            'diff --git a/app/Models/Team.php b/app/Models/Team.php',
            '--- a/app/Models/Team.php',
            '+++ b/app/Models/Team.php',
            '@@ -1,2 +1,4 @@',
            ' <?php',
            ' // Team',
            '+$team->archive();',
            '+$team->notify();',
            'diff --git a/tests/Feature/ArchiveTest.php b/tests/Feature/ArchiveTest.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/tests/Feature/ArchiveTest.php',
            '@@ -0,0 +1 @@',
            '+// Archive tests',
            'diff --git a/tests/Feature/TeamTest.php b/tests/Feature/TeamTest.php',
            '--- a/tests/Feature/TeamTest.php',
            '+++ b/tests/Feature/TeamTest.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// One more team test',
        ]);
    }

    public function test_a_passing_change_is_run_without_its_code_to_see_what_its_tests_and_routes_show()
    {
        $routes = ['sh', '-c', 'list the routes'];
        $tests = ['php', 'artisan', 'test', '--log-junit=new-tests.xml'];
        config(['builder.verification.change_evidence' => [
            'enabled' => true,
            'routes' => ['command' => $routes, 'timeout' => 60, 'report' => 'routes.json'],
            'tests' => ['command' => $tests, 'timeout' => 300, 'report' => 'new-tests.xml'],
        ]]);
        $route = fn (string $method, string $uri, array $middleware) => ['domain' => null, 'method' => $method, 'uri' => $uri, 'middleware' => $middleware];
        $case = fn (string $file, string $name, bool $passed) => "<testcase name=\"{$name}\" file=\"/workspace/tests/Feature/{$file}\">".($passed ? '' : '<failure/>').'</testcase>';
        $without = false;
        $this->driver->onExec = function (string $workspace, array $command) use ($routes, $tests, $route, $case, &$without) {
            $without = $without || in_array('--reverse', $command, true);

            if ($command === $routes) {
                $this->driver->files["{$workspace}:routes.json"] = json_encode($without
                    ? [$route('GET|HEAD', 'teams', ['web', 'auth'])]
                    : [$route('GET|HEAD', 'teams', ['web']), $route('POST', 'teams/{team}/archive', ['web', 'auth'])]);
            }

            if ($command === ['rm', '-f', 'new-tests.xml']) {
                unset($this->driver->files["{$workspace}:new-tests.xml"]);
            }

            if (array_slice($command, 0, 4) === $tests) {
                $this->driver->files["{$workspace}:new-tests.xml"] = '<testsuites>'.implode('', in_array('tests/Feature/ArchiveTest.php', $command, true) ? [
                    $case('ArchiveTest.php', 'test_owners_archive_teams', false),
                    $case('ArchiveTest.php', 'test_the_team_page_loads', true),
                    $case('TeamTest.php', 'test_owners_rename_teams', true),
                    $case('TeamTest.php', 'test_archived_teams_are_hidden', false),
                ] : [
                    $case('TeamTest.php', 'test_owners_rename_teams', true),
                ]).'</testsuites>';
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $request = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);

        app(RequestVerification::class)->handle($request);

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Unverified, $verification->status);
        $this->assertSame([
            'added' => [['route' => 'POST /teams/{team}/archive', 'middleware' => ['web', 'auth']]],
            'removed' => [],
            'changed' => [['route' => 'GET /teams', 'lost' => ['auth'], 'gained' => []]],
        ], $verification->evidence['routes']);
        $this->assertSame([
            ['file' => 'tests/Feature/ArchiveTest.php', 'name' => 'test_owners_archive_teams', 'without_change' => 'failed'],
            ['file' => 'tests/Feature/ArchiveTest.php', 'name' => 'test_the_team_page_loads', 'without_change' => 'passed'],
            ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_archived_teams_are_hidden', 'without_change' => 'failed'],
        ], $verification->evidence['new_tests']);

        // The whole change is taken out, then only what it did under tests/ is put back.
        $commands = array_column($this->driver->executed, 'command');
        $applies = array_values(array_filter($commands, fn (array $command) => array_slice($command, 0, 2) === ['git', 'apply']));
        $this->assertCount(3, $applies);
        $this->assertContains('--reverse', $applies[1]);
        $this->assertNotContains('--include=tests/*', $applies[1]);
        $this->assertNotContains('--reverse', $applies[2]);
        $this->assertContains('--include=tests/*', $applies[2]);
        $this->assertContains('--exclude=tests/Acceptance/*', $applies[2]);

        // Before the tests are put back, only the file the app already had is run.
        $runs = array_values(array_filter($commands, fn (array $command) => array_slice($command, 0, 4) === $tests));
        $this->assertSame([[...$tests, 'tests/Feature/TeamTest.php'], [...$tests, 'tests/Feature/ArchiveTest.php', 'tests/Feature/TeamTest.php']], $runs);

        // Measuring is never one of the checks.
        $this->assertSame(["Apply change #{$request->id}", 'Install', 'Key', 'Tests', 'Lint', 'Protected acceptance tests'], array_column($verification->results, 'name'));
    }

    public function test_a_follow_up_is_run_without_only_its_own_code_so_earlier_tests_are_not_counted_as_its_own()
    {
        $tests = ['php', 'artisan', 'test', '--log-junit=new-tests.xml'];
        config(['builder.verification.change_evidence' => [
            'enabled' => true,
            'routes' => ['command' => ['sh', '-c', 'list the routes'], 'timeout' => 60, 'report' => 'routes.json'],
            'tests' => ['command' => $tests, 'timeout' => 300, 'report' => 'new-tests.xml'],
        ]]);
        $parent = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Http/Controllers/RegisterController.php b/app/Http/Controllers/RegisterController.php',
            '--- a/app/Http/Controllers/RegisterController.php',
            '+++ b/app/Http/Controllers/RegisterController.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// Register button',
            'diff --git a/tests/Feature/RegisterTest.php b/tests/Feature/RegisterTest.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/tests/Feature/RegisterTest.php',
            '@@ -0,0 +1 @@',
            '+// Register tests',
        ])]);
        $followUp = FeatureRequest::factory()->generated()->for($parent->project)->create([
            'parent_id' => $parent->id,
            'patch' => $this->changeWithTests(),
        ]);

        app(RequestVerification::class)->handle($followUp);

        // The earlier change stays in; only the follow-up is taken out and its tests put back.
        $commands = array_column($this->driver->executed, 'command');
        $applies = array_values(array_filter($commands, fn (array $command) => array_slice($command, 0, 2) === ['git', 'apply']));
        $this->assertCount(4, $applies);
        $this->assertSame(FeatureRequest::LINEAGE_DIRECTORY.'/02.patch', last($applies[2]));
        $this->assertContains('--reverse', $applies[2]);
        $this->assertSame(FeatureRequest::LINEAGE_DIRECTORY.'/02.patch', last($applies[3]));
        $this->assertContains('--include=tests/*', $applies[3]);

        // The earlier change's tests are not run as the follow-up's.
        $runs = array_values(array_filter($commands, fn (array $command) => array_slice($command, 0, 4) === $tests));
        $this->assertSame([[...$tests, 'tests/Feature/TeamTest.php'], [...$tests, 'tests/Feature/ArchiveTest.php', 'tests/Feature/TeamTest.php']], $runs);
    }

    public function test_the_change_is_not_run_without_its_code_when_that_would_say_nothing()
    {
        $withoutCode = fn () => array_filter(array_column($this->driver->executed, 'command'), fn (array $command) => in_array('--reverse', $command, true));
        $verify = function (array $state) {
            $request = FeatureRequest::factory()->generated()->create($state);
            app(RequestVerification::class)->handle($request);

            return $request->verifications()->sole();
        };

        // A change that only adds tests has no code to take out.
        $this->assertNull($verify(['patch' => "diff --git a/tests/Feature/ArchiveTest.php b/tests/Feature/ArchiveTest.php\nnew file mode 100644\n--- /dev/null\n+++ b/tests/Feature/ArchiveTest.php\n@@ -0,0 +1 @@\n+// Tests"])->evidence);
        // A screen with no test has no routes of its own and no tests to run.
        $this->assertNull($verify(['patch' => "diff --git a/resources/js/pages/Teams.vue b/resources/js/pages/Teams.vue\n--- a/resources/js/pages/Teams.vue\n+++ b/resources/js/pages/Teams.vue\n@@ -1 +1,2 @@\n <p />\n+<p>Teams</p>"])->evidence);
        // The starting commit would need other packages installed.
        $this->assertNull($verify(['patch' => $this->changeWithTests()."\ndiff --git a/composer.json b/composer.json\n--- a/composer.json\n+++ b/composer.json\n@@ -1 +1,2 @@\n {\n+\n"])->evidence);

        config(['builder.verification.change_evidence.enabled' => false]);
        $this->assertNull($verify(['patch' => $this->changeWithTests()])->evidence);
        $this->assertEmpty($withoutCode());

        // Nor when a check failed: there is nothing to add evidence to.
        config(['builder.verification.change_evidence.enabled' => true]);
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: $command === ['pint', '--test'] ? 1 : 0, output: 'ok', errorOutput: '', durationMs: 5);
        $failed = $verify(['patch' => $this->changeWithTests()]);
        $this->assertSame(VerificationStatus::Failed, $failed->status);
        $this->assertNull($failed->evidence);
    }

    public function test_what_cannot_be_measured_without_the_change_is_not_kept_and_never_fails_it()
    {
        // The change cannot be taken out, the route list is not one, and no report is written.
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: in_array('--reverse', $command, true) ? 1 : 0, output: 'ok', errorOutput: '', durationMs: 5);
        $stuck = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);

        app(RequestVerification::class)->handle($stuck);

        $this->assertSame(VerificationStatus::Unverified, $stuck->verifications()->sole()->status);
        $this->assertNull($stuck->verifications()->sole()->evidence);

        // The tests cannot start without the change: that says nothing about any one of them.
        $this->driver->onExec = null;
        $silent = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);

        app(RequestVerification::class)->handle($silent);

        $this->assertSame(VerificationStatus::Unverified, $silent->verifications()->sole()->status);
        $this->assertNull($silent->verifications()->sole()->evidence);
    }

    public function test_the_new_lines_of_code_are_measured_from_the_suites_line_report()
    {
        $map = ['sh', '-c', 'make the test map'];
        config([
            'builder.verification.test_map' => [...config('builder.verification.test_map'), 'command' => $map, 'report' => 'covered.txt', 'listing' => 'tests.xml', 'lines' => 'lines.txt'],
            // The suite check's report, which the map's run writes.
            'builder.verification.checks.0.report' => 'storage/logs/junit.xml',
        ]);
        $this->driver->onExec = function (string $workspace, array $command) use ($map) {
            if ($command === $map) {
                // The suite check's own run, so it writes the check's report too.
                $this->driver->files["{$workspace}:storage/logs/junit.xml"] = '<testsuites><testcase name="test_owners_archive_teams" file="/workspace/tests/Feature/ArchiveTest.php"/></testsuites>';
                $this->driver->files["{$workspace}:covered.txt"] = implode("\n", [
                    '/workspace',
                    '<project source="/workspace/app"',
                    '<file name="Team.php" path="/Models"',
                    '<line nr="3"',
                    'covered by="Tests\Feature\ArchiveTest::test_owners_archive_teams"',
                ]);
                $this->driver->files["{$workspace}:tests.xml"] = '<?xml version="1.0"?><testSuite xmlns="https://xml.phpunit.de/testSuite"><tests><testClass name="Tests\Feature\ArchiveTest" file="/workspace/tests/Feature/ArchiveTest.php"><testMethod id="Tests\Feature\ArchiveTest::test_owners_archive_teams" name="test_owners_archive_teams"/></testClass></tests></testSuite>';
                $this->driver->files["{$workspace}:lines.txt"] = implode("\n", [
                    '/workspace',
                    '<file name="/workspace/app/Models/Team.php"',
                    '<line num="3" type="stmt" count="1"',
                    '<line num="4" type="stmt" count="0"',
                ]);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $request = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);

        app(RequestVerification::class)->handle($request);

        $this->assertSame(
            ['lines' => 2, 'run' => 1, 'own_tests_only' => 1, 'unrun' => ['app/Models/Team.php' => [4]]],
            $request->verifications()->sole()->evidence['new_code'],
        );
    }

    public function test_small_mistakes_in_the_new_code_show_which_behaviour_the_tests_pin_down()
    {
        $map = ['sh', '-c', 'make the test map'];
        config([
            'builder.verification.test_map' => [...config('builder.verification.test_map'), 'command' => $map, 'report' => 'covered.txt', 'listing' => 'tests.xml'],
            'builder.verification.mutants.max' => 5,
            'builder.verification.checks.0.report' => 'storage/logs/junit.xml',
        ]);
        $patch = implode("\n", [
            'diff --git a/app/Models/Team.php b/app/Models/Team.php',
            '--- a/app/Models/Team.php',
            '+++ b/app/Models/Team.php',
            '@@ -1,2 +1,5 @@',
            ' <?php',
            ' // Team',
            '+if ($user->id === $team->owner_id) {',
            '+    $team->save();',
            '+}',
            '',
        ]);
        $source = "<?php\n// Team\nif (\$user->id === \$team->owner_id) {\n    \$team->save();\n}\n";
        $mutated = null;
        $this->driver->onExec = function (string $workspace, array $command) use ($map, $source, &$mutated) {
            $mutated = $workspace;

            if ($command === $map) {
                $this->driver->files["{$workspace}:storage/logs/junit.xml"] = '<testsuites><testcase name="test_owners_save_teams" file="/workspace/tests/Feature/TeamTest.php"/></testsuites>';
                $this->driver->files["{$workspace}:app/Models/Team.php"] = $source;
                $this->driver->files["{$workspace}:covered.txt"] = implode("\n", [
                    '/workspace',
                    '<project source="/workspace/app"',
                    '<file name="Team.php" path="/Models"',
                    '<line nr="3"',
                    'covered by="Tests\Feature\TeamTest::test_owners_save_teams"',
                    '<line nr="4"',
                    'covered by="Tests\Feature\TeamTest::test_owners_save_teams"',
                ]);
                $this->driver->files["{$workspace}:tests.xml"] = '<?xml version="1.0"?><testSuite xmlns="https://xml.phpunit.de/testSuite"><tests><testClass name="Tests\Feature\TeamTest" file="/workspace/tests/Feature/TeamTest.php"><testMethod id="Tests\Feature\TeamTest::test_owners_save_teams" name="test_owners_save_teams"/></testClass></tests></testSuite>';
            }

            if (array_slice($command, 0, 4) === config('builder.verification.mutants.command')) {
                // The test notices the comparison turned around, but not the save left out.
                $failed = str_contains($this->driver->files["{$workspace}:app/Models/Team.php"], '!==');
                $this->driver->files["{$workspace}:storage/logs/mutants.xml"] = '<testsuites><testcase name="test_owners_save_teams" file="/workspace/tests/Feature/TeamTest.php">'.($failed ? '<failure>no</failure>' : '').'</testcase></testsuites>';

                return new CommandResult(exitCode: $failed ? 1 : 0, output: '', errorOutput: '', durationMs: 5);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $request = FeatureRequest::factory()->generated()->create(['patch' => $patch]);

        app(RequestVerification::class)->handle($request);

        $this->assertSame([
            'tried' => 2,
            'caught' => 1,
            'survived' => [['file' => 'app/Models/Team.php', 'line' => 4, 'was' => '$team->save();', 'now' => '']],
        ], $request->verifications()->sole()->evidence['mutants']);
        $this->assertSame($source, $this->driver->files["{$mutated}:app/Models/Team.php"], 'Each file is put back as it was.');
        $this->assertContains(['php', 'artisan', 'test', '--log-junit=storage/logs/mutants.xml', 'tests/Feature/TeamTest.php'], array_column($this->driver->executed, 'command'));
    }

    public function test_what_the_changes_code_did_in_the_requests_of_the_tests_is_measured_from_the_recording()
    {
        $map = ['sh', '-c', 'make the test map'];
        $scan = ['sh', '-c', 'scan for shortcuts'];
        config([
            'builder.verification.test_map' => [...config('builder.verification.test_map'), 'command' => $map, 'report' => 'covered.txt', 'listing' => 'tests.xml'],
            'builder.verification.checks.0.report' => 'storage/logs/junit.xml',
            'builder.verification.traces' => ['enabled' => true, 'report' => 'trace.jsonl', 'repeats' => 3],
            'builder.verification.shortcuts' => [...config('builder.verification.shortcuts'), 'enabled' => true, 'command' => $scan, 'report' => 'shortcuts.json'],
            'builder.verification.change_evidence.routes' => ['command' => ['sh', '-c', 'list the routes'], 'timeout' => 60, 'report' => 'routes.json'],
        ]);
        $request = fn (string $method, string $route, array $effects) => json_encode(['test' => 'Tests\Feature\ArchiveTest::test_owners_archive_teams', 'method' => $method, 'route' => $route, 'status' => 200, 'refused' => false, 'effects' => $effects, 'blind' => []]);
        $lookup = ['kind' => 'query', 'sql' => 'select * from "users" where "id" = ? limit 1', 'open' => 0, 'at' => 'app/Models/Team.php:4'];
        $without = false;
        $this->driver->onExec = function (string $workspace, array $command) use ($map, $scan, $request, $lookup, &$without) {
            $without = $without || in_array('--reverse', $command, true);

            if ($command === $map) {
                // The suite check's own run, so it writes the check's report too.
                $this->driver->files["{$workspace}:storage/logs/junit.xml"] = '<testsuites><testcase name="test_owners_archive_teams" file="/workspace/tests/Feature/ArchiveTest.php"/></testsuites>';
                $this->driver->files["{$workspace}:covered.txt"] = "/workspace\n<project source=\"/workspace/app\"\n<file name=\"Team.php\" path=\"/Models\"\ncovered by=\"Tests\\Feature\\ArchiveTest::test_owners_archive_teams\"";
                $this->driver->files["{$workspace}:trace.jsonl"] = implode("\n", [
                    // A page that saves from a line the change added.
                    $request('GET', '/teams', [['kind' => 'query', 'sql' => 'update "teams" set "seen_at" = ?', 'open' => 0, 'at' => 'app/Models/Team.php:3']]),
                    // The same lookup three times from one new line.
                    $request('GET', '/teams/{team}', [$lookup, $lookup, $lookup]),
                    // Old code on a route the change added.
                    $request('GET', '/teams/{team}/archive', [['kind' => 'query', 'sql' => 'delete from "teams" where "id" = ?', 'open' => 0, 'at' => 'app/Support/Old.php:9']]),
                ]);
            }

            if (array_slice($command, 0, 3) === $scan) {
                $this->driver->files["{$workspace}:shortcuts.json"] = json_encode(['schema' => 1, 'tool' => 'sloppy', 'findings' => []]);
            }

            if ($command === ['sh', '-c', 'list the routes']) {
                $this->driver->files["{$workspace}:routes.json"] = json_encode($without ? [] : [['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'teams/{team}/archive', 'middleware' => ['web', 'auth']]]);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $change = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $traces = $verification->evidence['traces'];
        $this->assertSame([3, 3, 0, 0], [$traces['requests'], $traces['reached'], $traces['unseen'], $traces['existing']]);
        $this->assertSame([
            ['kind' => 'saved_on_read', 'route' => 'GET /teams', 'what' => 'update teams', 'at' => 'app/Models/Team.php:3', 'test' => 'Tests\Feature\ArchiveTest::test_owners_archive_teams'],
            ['kind' => 'saved_on_read', 'route' => 'GET /teams/{team}/archive', 'what' => 'delete teams', 'at' => 'app/Support/Old.php:9', 'test' => 'Tests\Feature\ArchiveTest::test_owners_archive_teams'],
        ], $traces['findings']);

        // The repeated lookup joins the shortcuts, which never hold the change back.
        $this->assertSame([['rule' => 'SL204', 'path' => 'app/Models/Team.php', 'line' => 4]], $verification->shortcuts);
        $this->assertSame(VerificationStatus::Unverified, $verification->status);

        // Nothing more ran for it: the recording comes from the coverage run.
        $this->assertSame(1, collect($this->driver->executed)->where('command', $map)->count());

        // Without a recording nothing is kept, and switching it off reads none.
        config(['builder.verification.traces.enabled' => false]);
        $off = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);

        app(RequestVerification::class)->handle($off);

        $this->assertArrayNotHasKey('traces', $off->verifications()->sole()->evidence ?? []);
    }

    public function test_saves_where_laravel_checks_or_starts_are_seen_in_the_recording_and_read_from_the_code()
    {
        $map = ['sh', '-c', 'make the test map'];
        config([
            'builder.verification.test_map' => [...config('builder.verification.test_map'), 'command' => $map, 'report' => 'covered.txt', 'listing' => 'tests.xml'],
            'builder.verification.checks.0.report' => 'storage/logs/junit.xml',
            'builder.verification.traces' => ['enabled' => true, 'report' => 'trace.jsonl', 'repeats' => 3],
            'builder.verification.boundaries' => ['enabled' => true, 'phases' => ['authorization', 'validation', 'rendering']],
        ]);
        $policy = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public function view(\$user, \$post): bool\n    {\n        \$post->increment('views');\n        \$post->author->notify(new \\App\\Notifications\\Viewed);\n        return true;\n    }\n}\n";
        $provider = "<?php\nnamespace App\\Providers;\nuse Illuminate\\Support\\ServiceProvider;\nclass AppServiceProvider extends ServiceProvider\n{\n    public function boot(): void\n    {\n        \\Illuminate\\Support\\Facades\\View::share('categories', \\App\\Models\\Category::all());\n    }\n}\n";
        $added = fn (string $path, string $code) => implode("\n", [
            "diff --git a/{$path} b/{$path}",
            'new file mode 100644',
            '--- /dev/null',
            "+++ b/{$path}",
            '@@ -0,0 +1,'.substr_count($code, "\n").' @@',
            ...array_map(fn (string $line) => '+'.$line, explode("\n", rtrim($code, "\n"))),
        ]);
        $this->driver->onExec = function (string $workspace, array $command) use ($map, $policy, $provider) {
            $this->driver->files["{$workspace}:app/Policies/PostPolicy.php"] = $policy;
            $this->driver->files["{$workspace}:app/Providers/AppServiceProvider.php"] = $provider;

            if ($command === $map) {
                // The suite check's own run, so it writes the check's report too.
                $this->driver->files["{$workspace}:storage/logs/junit.xml"] = '<testsuites><testcase name="test_owners_archive_teams" file="/workspace/tests/Feature/ArchiveTest.php"/></testsuites>';
                $this->driver->files["{$workspace}:trace.jsonl"] = json_encode(['test' => 'Tests\Feature\PostTest::test_people_read_posts', 'method' => 'GET', 'route' => '/posts', 'status' => 200, 'refused' => false, 'blind' => [], 'effects' => [
                    ['kind' => 'query', 'sql' => 'update "posts" set "views" = "views" + 1', 'open' => 0, 'at' => 'app/Policies/PostPolicy.php:7', 'phase' => 'authorization', 'frames' => ['App\Policies\PostPolicy::view']],
                ]]);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $change = FeatureRequest::factory()->generated()->create(['patch' => $added('app/Policies/PostPolicy.php', $policy)."\n".$added('app/Providers/AppServiceProvider.php', $provider)."\n".$this->changeWithTests()]);

        app(RequestVerification::class)->handle($change);

        $boundaries = $change->verifications()->sole()->evidence['boundaries'];
        // The save was seen, so it is said once, as seen.
        $this->assertSame([['kind' => 'changed_while_authorizing', 'route' => 'GET /posts', 'what' => 'update posts', 'at' => 'app/Policies/PostPolicy.php:7', 'in' => 'App\Policies\PostPolicy::view', 'test' => 'Tests\Feature\PostTest::test_people_read_posts']], $boundaries['findings']);
        // What no test ran, and the app's start, are read from the code.
        $this->assertSame([
            ['kind' => 'changed_while_authorizing', 'what' => 'notification', 'at' => 'app/Policies/PostPolicy.php:8', 'in' => 'App\Policies\PostPolicy::view'],
            ['kind' => 'changed_while_booting', 'what' => 'query', 'at' => 'app/Providers/AppServiceProvider.php:8', 'in' => 'App\Providers\AppServiceProvider::boot'],
        ], $boundaries['read']);

        // Switched off, nothing is read or kept.
        config(['builder.verification.boundaries.enabled' => false]);
        $off = FeatureRequest::factory()->generated()->create(['patch' => $change->patch]);

        app(RequestVerification::class)->handle($off);

        $this->assertArrayNotHasKey('boundaries', $off->verifications()->sole()->evidence ?? []);
    }

    public function test_a_call_to_an_outside_service_from_outside_the_area_the_app_calls_it_from_is_kept()
    {
        $map = ['sh', '-c', 'make the test map'];
        config([
            'builder.verification.test_map' => [...config('builder.verification.test_map'), 'command' => $map, 'report' => 'covered.txt', 'listing' => 'tests.xml'],
            'builder.verification.checks.0.report' => 'storage/logs/junit.xml',
            'builder.verification.traces' => ['enabled' => true, 'report' => 'trace.jsonl', 'repeats' => 3],
            'builder.verification.boundaries' => ['enabled' => true, 'phases' => ['authorization', 'validation', 'rendering']],
        ]);
        $this->mock(ReadProjectContext::class, fn ($mock) => $mock->shouldReceive('current')->andReturn(new ProjectContext(capabilities: [
            'billing' => new Capability('billing', 'Billing', paths: ['app/Billing/*']),
        ])));
        $controller = "<?php\nnamespace App\\Http\\Controllers;\nclass CheckoutController\n{\n    public function store()\n    {\n        \\Illuminate\\Support\\Facades\\Http::post('https://api.stripe.com/v1/charges');\n    }\n}\n";
        $this->driver->onExec = function (string $workspace, array $command) use ($map) {
            if ($command === $map) {
                // The suite check's own run, so it writes the check's report too.
                $this->driver->files["{$workspace}:storage/logs/junit.xml"] = '<testsuites><testcase name="test_owners_archive_teams" file="/workspace/tests/Feature/ArchiveTest.php"/></testsuites>';
                $this->driver->files["{$workspace}:trace.jsonl"] = json_encode(['test' => 'Tests\Feature\CheckoutTest::test_people_pay', 'method' => 'POST', 'route' => '/checkout', 'status' => 200, 'refused' => false, 'blind' => [], 'effects' => [
                    ['kind' => 'http', 'what' => 'POST api.stripe.com', 'open' => 0, 'at' => 'app/Billing/StripeGateway.php:31', 'phase' => 'handling', 'frames' => ['App\Billing\StripeGateway::charge']],
                    ['kind' => 'http', 'what' => 'POST api.stripe.com', 'open' => 0, 'at' => 'app/Http/Controllers/CheckoutController.php:7', 'phase' => 'handling', 'frames' => ['App\Http\Controllers\CheckoutController::store']],
                ]]);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $patch = implode("\n", [
            'diff --git a/app/Http/Controllers/CheckoutController.php b/app/Http/Controllers/CheckoutController.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/app/Http/Controllers/CheckoutController.php',
            '@@ -0,0 +1,9 @@',
            ...array_map(fn (string $line) => '+'.$line, explode("\n", rtrim($controller, "\n"))),
        ]);
        $change = FeatureRequest::factory()->generated()->create(['patch' => $patch."\n".$this->changeWithTests()]);

        app(RequestVerification::class)->handle($change);

        $this->assertSame(['services' => 1, 'findings' => [
            ['route' => 'POST /checkout', 'what' => 'http POST api.stripe.com', 'at' => 'app/Http/Controllers/CheckoutController.php:7', 'in' => 'App\Http\Controllers\CheckoutController::store', 'from' => [], 'home' => ['Billing'], 'test' => 'Tests\Feature\CheckoutTest::test_people_pay'],
        ]], $change->verifications()->sole()->evidence['containment']);
    }

    public function test_the_work_per_request_of_each_area_is_kept_with_the_areas_that_grew_past_their_ceiling()
    {
        $map = ['sh', '-c', 'make the test map'];
        config([
            'builder.verification.test_map' => [...config('builder.verification.test_map'), 'command' => $map, 'report' => 'covered.txt', 'listing' => 'tests.xml'],
            'builder.verification.checks.0.report' => 'storage/logs/junit.xml',
            'builder.verification.traces' => ['enabled' => true, 'report' => 'trace.jsonl', 'repeats' => 3],
            'builder.verification.drift' => ['enabled' => true, 'least' => 2, 'slack' => 0.1, 'tolerance' => 0.25, 'strict' => 1.0],
        ]);
        $this->mock(ReadProjectContext::class, fn ($mock) => $mock->shouldReceive('current')->andReturn(new ProjectContext(capabilities: [
            'billing' => new Capability('billing', 'Billing', paths: ['app/Billing/*']),
        ])));
        $this->driver->onExec = function (string $workspace, array $command) use ($map) {
            if ($command === $map) {
                // The suite check's own run, so it writes the check's report too.
                $this->driver->files["{$workspace}:storage/logs/junit.xml"] = '<testsuites><testcase name="test_owners_archive_teams" file="/workspace/tests/Feature/ArchiveTest.php"/></testsuites>';
                $request = fn (int $queries) => json_encode(['test' => 'Tests\Feature\InvoiceTest::test_people_read_invoices', 'method' => 'GET', 'route' => '/invoices', 'status' => 200, 'refused' => false, 'blind' => [], 'effects' => array_fill(0, $queries, ['kind' => 'query', 'sql' => 'select * from invoices', 'open' => 0, 'at' => 'app/Billing/Invoices.php:12'])]);
                $this->driver->files["{$workspace}:trace.jsonl"] = $request(4)."\n".$request(6);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $change = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);
        $change->project->forceFill(['drift_ceilings' => ['billing' => 2.0]])->save();

        app(RequestVerification::class)->handle($change);

        $this->assertSame([
            'areas' => ['billing' => ['requests' => 2, 'effects' => 10, 'per' => 5]],
            'findings' => [['area' => 'billing', 'per' => 5, 'ceiling' => 2, 'far' => true, 'name' => 'Billing']],
        ], $change->verifications()->sole()->evidence['drift']);
    }

    public function test_one_failure_at_a_time_is_caused_where_the_changes_code_sends_and_what_stayed_is_kept()
    {
        $map = ['sh', '-c', 'make the test map'];
        $fail = ['sh', '-c', 'run one test with one failure', 'sh'];
        config([
            'builder.verification.test_map' => [...config('builder.verification.test_map'), 'command' => $map, 'report' => 'covered.txt', 'listing' => 'tests.xml'],
            'builder.verification.checks.0.report' => 'storage/logs/junit.xml',
            'builder.verification.traces' => ['enabled' => true, 'report' => 'trace.jsonl', 'repeats' => 3],
            'builder.verification.faults' => ['enabled' => true, 'points' => 2, 'seconds' => 60, 'command' => $fail, 'timeout' => 30, 'report' => 'failed.jsonl'],
        ]);
        $test = 'Tests\Feature\ArchiveTest::test_owners_archive_teams';
        $request = fn (int $status, array $effects, array $extra = []) => json_encode(['test' => $test, 'n' => 0, 'method' => 'POST', 'route' => '/teams/{team}/archive', 'status' => $status, 'refused' => $status >= 400, 'effects' => $effects, 'blind' => [], ...$extra]);
        $saved = ['kind' => 'query', 'sql' => 'update "teams" set "archived_at" = ?', 'open' => 0, 'at' => 'app/Models/Team.php:3'];
        $mail = ['kind' => 'mail', 'what' => 'App\Mail\TeamArchived', 'open' => 0, 'at' => 'app/Models/Team.php:4'];
        $hook = ['kind' => 'http', 'what' => 'POST hooks.example.com', 'open' => 0, 'at' => 'app/Models/Team.php:4'];
        $old = ['kind' => 'http', 'what' => 'POST chat.example.com', 'open' => 0, 'at' => 'app/Support/Old.php:9'];
        $this->driver->onExec = function (string $workspace, array $command) use ($map, $fail, $request, $saved, $mail, $hook, $old) {
            if ($command === $map) {
                // The suite check's own run, so it writes the check's report too.
                $this->driver->files["{$workspace}:storage/logs/junit.xml"] = '<testsuites><testcase name="test_owners_archive_teams" file="/workspace/tests/Feature/ArchiveTest.php"/></testsuites>';
                $this->driver->files["{$workspace}:covered.txt"] = "/workspace\n<project source=\"/workspace/app\"\n<file name=\"Team.php\" path=\"/Models\"\ncovered by=\"Tests\\Feature\\ArchiveTest::test_owners_archive_teams\"";
                $this->driver->files["{$workspace}:trace.jsonl"] = $request(302, [$saved, $mail, $hook, $old]);
            }

            if (array_slice($command, 0, 4) === $fail) {
                $fault = json_decode((string) (end($this->driver->environments)['TRACE_RECORDER_FAULT'] ?? ''), true);

                // The email fails and the person gets an error. The outside
                // call's failure does not happen: the test took another way.
                $this->driver->files["{$workspace}:failed.jsonl"] = $fault['kind'] === 'mail'
                    ? $request(500, [$saved, $mail], ['fault' => $fault['effect']])
                    : $request(302, [$saved]);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $change = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $this->assertSame([
            'points' => 3,
            'run' => 1,
            'missed' => 1,
            'existing' => 0,
            'findings' => [['kind' => 'saved_then_failed', 'route' => 'POST /teams/{team}/archive', 'failed' => 'mail App\Mail\TeamArchived', 'what' => 'update teams', 'at' => 'app/Models/Team.php:4', 'test' => $test]],
        ], $verification->evidence['faults']);
        // It never changes what the checks said.
        $this->assertSame(VerificationStatus::Unverified, $verification->status);

        // Two places were tried, the change's own first: each ran its one
        // test again, told what to make fail, while the change was still in.
        $commands = array_column($this->driver->executed, 'command');
        $tried = array_keys(array_filter($commands, fn (array $command) => array_slice($command, 0, 4) === $fail));
        $this->assertSame([[...$fail, 'test_owners_archive_teams'], [...$fail, 'test_owners_archive_teams']], array_values(array_intersect_key($commands, array_flip($tried))));
        $this->assertSame([
            ['test' => $test, 'request' => 0, 'effect' => 1, 'kind' => 'mail'],
            ['test' => $test, 'request' => 0, 'effect' => 2, 'kind' => 'http'],
        ], array_map(fn (int $position) => json_decode($this->driver->environments[$position]['TRACE_RECORDER_FAULT'], true), $tried));
        $this->assertLessThan(array_key_first(array_filter($commands, fn (array $command) => in_array('--reverse', $command, true))), max($tried));

        // Switched off, nothing is made to fail.
        config(['builder.verification.faults.enabled' => false]);
        $this->driver->executed = [];
        $off = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);

        app(RequestVerification::class)->handle($off);

        $this->assertArrayNotHasKey('faults', $off->verifications()->sole()->evidence ?? []);
        $this->assertSame([], array_filter(array_column($this->driver->executed, 'command'), fn (array $command) => array_slice($command, 0, 4) === $fail));
    }

    public function test_the_migrations_a_change_adds_are_run_undone_and_run_again_and_an_edited_one_is_noted()
    {
        $added = 'database/migrations/2026_10_05_000000_add_notes_to_teams.php';
        $edited = 'database/migrations/2026_01_01_000000_create_teams_table.php';
        $this->driver->onExec = function (string $workspace, array $command) {
            if (array_slice($command, 0, 2) === ['sh', '-c'] && str_contains($command[2], 'migrate:rollback')) {
                $this->driver->files["{$workspace}:storage/logs/migrations/report.json"] = '{"up":0,"down":1,"again":-1}';
                $this->driver->files["{$workspace}:storage/logs/migrations/down.log"] = 'SQLSTATE[42P01]: Undefined table: notes';
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $change = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            "diff --git a/{$added} b/{$added}",
            'new file mode 100644',
            '--- /dev/null',
            "+++ b/{$added}",
            '@@ -0,0 +1 @@',
            '+<?php',
            "diff --git a/{$edited} b/{$edited}",
            "--- a/{$edited}",
            "+++ b/{$edited}",
            '@@ -1 +1 @@',
            '-old',
            '+new',
            '',
        ])]);

        app(RequestVerification::class)->handle($change);

        // The added migrations are named to the script, so only they are undone.
        $runs = array_values(array_filter(array_column($this->driver->executed, 'command'), fn (array $command) => str_contains($command[2] ?? '', 'migrate:rollback')));
        $this->assertCount(1, $runs);
        $this->assertSame(['sh', $added], array_slice($runs[0], 3));
        $this->assertSame([
            'added' => [$added],
            'edited' => [$edited],
            'up' => true,
            'down' => false,
            'again' => null,
            'failed' => 'down',
            'output' => 'SQLSTATE[42P01]: Undefined table: notes',
            'risks' => [],
        ], $change->verifications()->sole()->evidence['migrations']);

        // A change with no migrations runs nothing, and the check can be turned off.
        $plain = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);
        app(RequestVerification::class)->handle($plain);
        $this->assertArrayNotHasKey('migrations', $plain->verifications()->sole()->evidence ?? []);

        config(['builder.verification.migrations.enabled' => false]);
        $off = FeatureRequest::factory()->generated()->create(['patch' => $change->patch]);
        app(RequestVerification::class)->handle($off);
        $this->assertArrayNotHasKey('migrations', $off->verifications()->sole()->evidence ?? []);
        $this->assertCount(1, array_filter(array_column($this->driver->executed, 'command'), fn (array $command) => str_contains($command[2] ?? '', 'migrate:rollback')));
    }

    public function test_the_work_a_change_queues_is_read_for_how_it_tries_again_and_fails()
    {
        $job = "<?php\n\nnamespace App\\Jobs;\n\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\n\nclass SendReminder implements ShouldQueue\n{\n    public \$tries = 3;\n}\n";
        $patch = "diff --git a/app/Jobs/SendReminder.php b/app/Jobs/SendReminder.php\nnew file mode 100644\n--- /dev/null\n+++ b/app/Jobs/SendReminder.php\n@@ -0,0 +1 @@\n+<?php\n";
        $this->driver->onExec = function (string $workspace, array $command) use ($job) {
            $this->driver->files["{$workspace}:app/Jobs/SendReminder.php"] = $job;

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        $change = FeatureRequest::factory()->generated()->create(['patch' => $patch]);

        app(RequestVerification::class)->handle($change);

        $this->assertSame([
            ['class' => 'App\\Jobs\\SendReminder', 'kind' => 'job', 'at' => 'app/Jobs/SendReminder.php:7', 'missing' => ['backoff', 'failed']],
        ], $change->verifications()->sole()->evidence['queued']);

        // A change that queues nothing keeps nothing, and the check can be turned off.
        $plain = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);
        app(RequestVerification::class)->handle($plain);
        $this->assertArrayNotHasKey('queued', $plain->verifications()->sole()->evidence ?? []);

        config(['builder.verification.queued.enabled' => false]);
        $off = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        app(RequestVerification::class)->handle($off);
        $this->assertArrayNotHasKey('queued', $off->verifications()->sole()->evidence ?? []);
    }

    public function test_the_tables_a_change_gives_an_owner_are_read_for_what_keeps_each_owners_records_apart()
    {
        $migration = 'database/migrations/2026_10_05_000000_create_bookings_table.php';
        $files = [
            $migration => "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class\n{\n    public function up(): void\n    {\n        Schema::create('bookings', function (\$table) {\n            \$table->foreignId('user_id');\n        });\n    }\n};\n",
            'app/Models/Booking.php' => "<?php\n\nnamespace App\\Models;\n\nclass Booking\n{\n}\n",
        ];
        $patch = implode('', array_map(fn (string $path) => "diff --git a/{$path} b/{$path}\nnew file mode 100644\n--- /dev/null\n+++ b/{$path}\n@@ -0,0 +1 @@\n+<?php\n", array_keys($files)));
        $this->driver->onExec = function (string $workspace) use ($files) {
            foreach ($files as $path => $contents) {
                $this->driver->files["{$workspace}:{$path}"] = $contents;
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        config(['builder.verification.migrations.enabled' => false]);
        $change = FeatureRequest::factory()->generated()->create(['patch' => $patch]);

        app(RequestVerification::class)->handle($change);

        $this->assertSame([
            ['table' => 'bookings', 'column' => 'user_id', 'at' => "{$migration}:10", 'model' => 'App\\Models\\Booking', 'guard' => null, 'policy' => null],
        ], $change->verifications()->sole()->evidence['owners']);

        // A change that gives no table an owner keeps nothing, and the check can be turned off.
        $plain = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);
        app(RequestVerification::class)->handle($plain);
        $this->assertArrayNotHasKey('owners', $plain->verifications()->sole()->evidence ?? []);

        config(['builder.verification.owners.enabled' => false]);
        $off = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        app(RequestVerification::class)->handle($off);
        $this->assertArrayNotHasKey('owners', $off->verifications()->sole()->evidence ?? []);
    }

    public function test_the_emails_and_text_messages_a_change_adds_are_read_for_the_owner_to_approve()
    {
        $path = 'app/Mail/InvoicePaid.php';
        $patch = "diff --git a/{$path} b/{$path}\nnew file mode 100644\n--- /dev/null\n+++ b/{$path}\n@@ -0,0 +1 @@\n+<?php\n";
        $this->driver->onExec = function (string $workspace) use ($path) {
            $this->driver->files["{$workspace}:{$path}"] = "<?php\n\nnamespace App\\Mail;\n\nuse Illuminate\\Mail\\Mailable;\n\nclass InvoicePaid extends Mailable\n{\n}\n";

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        config(['builder.verification.migrations.enabled' => false]);
        $change = FeatureRequest::factory()->generated()->create(['patch' => $patch]);

        app(RequestVerification::class)->handle($change);

        $this->assertSame([
            ['class' => 'App\\Mail\\InvoicePaid', 'channels' => ['mail'], 'at' => "{$path}:7"],
        ], $change->verifications()->sole()->evidence['messages']);

        // A change that sends nothing new keeps nothing, and the check can be turned off.
        $plain = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);
        app(RequestVerification::class)->handle($plain);
        $this->assertArrayNotHasKey('messages', $plain->verifications()->sole()->evidence ?? []);

        config(['builder.verification.messages.enabled' => false]);
        $off = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        app(RequestVerification::class)->handle($off);
        $this->assertArrayNotHasKey('messages', $off->verifications()->sole()->evidence ?? []);
    }

    public function test_laravels_structure_rules_send_back_only_the_problems_the_change_brought_and_never_touch_the_patch()
    {
        $step = collect((require config_path('builder.php'))['verification']['checks'])->firstWhere('name', ArchPresets::CHECK);
        config(['builder.verification.checks' => [$step], 'builder.verification.migrations.enabled' => false]);
        $verify = function (string $before, string $after, bool $hasPresets = true, bool $timesOut = false) {
            $atStart = false;
            $this->driver->onExec = function (string $workspace, array $command) use (&$atStart, $before, $after, $hasPresets, $timesOut) {
                if ($command[0] === 'git') {
                    $atStart = in_array('--reverse', $command, true);
                }

                return match (true) {
                    $command === ['test', '-e', ArchPresets::NEEDS] => new CommandResult(exitCode: $hasPresets ? 0 : 1, output: '', errorOutput: '', durationMs: 5),
                    $command[0] === 'sh' && str_contains($command[2] ?? '', ArchPresets::DIRECTORY) => new CommandResult(exitCode: 1, output: $atStart ? $before : $after, errorOutput: '', durationMs: 10, timedOut: $timesOut),
                    default => new CommandResult(exitCode: 0, output: '', errorOutput: '', durationMs: 5),
                };
            };
            $request = FeatureRequest::factory()->generated()->create();
            $patch = $request->patch;

            app(RequestVerification::class)->handle($request);

            // Whatever the check did, the change is the one the coder made.
            $this->assertSame($patch, $request->refresh()->patch);

            return collect($request->verifications()->sole()->results)->firstWhere('name', ArchPresets::CHECK);
        };
        $old = "preset → laravel Expecting 'app/Http/Controllers/RunnerController.php' not to have public methods besides 'index'.";
        $new = "preset → laravel Expecting 'app/Http/Controllers/RoomController.php' not to have public methods besides 'index'.";

        // The change brought a problem ahead of the app's older one.
        $brought = $verify($old, $new);
        $this->assertSame(['failed', 'failed', [$new]], [$brought['outcome'], $brought['at_start'], $brought['new_problems']]);

        // The app's older problem, and nothing new ahead of it.
        $older = $verify($old, $old);
        $this->assertSame(['failed', 'failed', []], [$older['outcome'], $older['at_start'], $older['new_problems']]);

        // An app whose Pest has no presets is not checked, and says so.
        $this->assertSame('not_applicable', $verify('', '', hasPresets: false)['outcome']);

        // A run stopped by its time limit says nothing about the code, and
        // it leaves the change as it was.
        $this->assertSame('errored', $verify($old, $new, timesOut: true)['outcome']);
    }

    public function test_the_packages_a_change_adds_to_a_lockfile_are_read_for_the_dependency_policy()
    {
        $lock = fn (array $names) => json_encode(['packages' => array_map(fn (string $name) => ['name' => $name, 'version' => 'v1.0.0', 'license' => ['MIT'], 'notification-url' => 'https://packagist.org/downloads/'], $names)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        $files = [
            'composer.lock' => $lock(['laravel/framework', 'acme/pdf']),
            'composer.json' => '{"require": {"laravel/framework": "^13.0", "acme/pdf": "^1.0"}}',
        ];
        $patch = "diff --git a/composer.lock b/composer.lock\nnew file mode 100644\n--- /dev/null\n+++ b/composer.lock\n@@ -0,0 +1 @@\n+{\n";
        $this->driver->onExec = function (string $workspace) use ($files) {
            foreach ($files as $path => $contents) {
                $this->driver->files["{$workspace}:{$path}"] = $contents;
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
        config(['builder.verification.migrations.enabled' => false, 'builder.verification.packages.allowed.composer' => ['laravel/*']]);
        $change = FeatureRequest::factory()->generated()->create(['patch' => $patch]);

        app(RequestVerification::class)->handle($change);

        $this->assertSame(['added' => 2, 'problems' => [
            ['name' => 'acme/pdf', 'version' => 'v1.0.0', 'license' => ['MIT'], 'source' => 'https://packagist.org/downloads/', 'manager' => 'composer', 'at' => 'composer.lock', 'direct' => true, 'rules' => ['package_unlisted']],
        ]], $change->verifications()->sole()->evidence['packages']);

        // A change that adds no package keeps nothing, and the check can be turned off.
        $plain = FeatureRequest::factory()->generated()->create(['patch' => $this->changeWithTests()]);
        app(RequestVerification::class)->handle($plain);
        $this->assertArrayNotHasKey('packages', $plain->verifications()->sole()->evidence ?? []);

        config(['builder.verification.packages.enabled' => false]);
        $off = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        app(RequestVerification::class)->handle($off);
        $this->assertArrayNotHasKey('packages', $off->verifications()->sole()->evidence ?? []);
    }
}
