<?php

namespace Tests\Feature\Features;

use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Models\Verification;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]);
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
            ->post(route('feature-requests.verifications.store', $followUp))
            ->assertRedirect(route('feature-requests.show', $followUp));

        $verification = $followUp->verifications()->sole();
        $this->assertSame(VerificationStatus::Passed, $verification->status);

        $workspaceId = $this->driver->copies[0]['workspace'];
        $this->assertSame($parent->project->source_path, $this->driver->copies[0]['source']);
        $this->assertSame('PARENT PATCH', $this->driver->files["{$workspaceId}:.builder/01.patch"]);
        $this->assertSame('FOLLOW-UP PATCH', $this->driver->files["{$workspaceId}:.builder/02.patch"]);

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
}
