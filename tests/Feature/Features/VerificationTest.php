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
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [
                ['name' => 'Install', 'command' => ['composer', 'install'], 'timeout' => 600],
            ],
            'builder.verification.checks' => [
                ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300],
                ['name' => 'Lint', 'command' => ['pint', '--test'], 'timeout' => 60],
            ],
        ]);
    }

    public function test_a_follow_up_is_verified_with_every_change_in_its_lineage_applied_in_order()
    {
        $parent = FeatureRequest::factory()->generated()->create(['patch' => 'PARENT PATCH']);
        $followUp = FeatureRequest::factory()->generated()->for($parent->project)->create([
            'parent_id' => $parent->id,
            'target_step' => 'permission',
            'patch' => 'FOLLOW-UP PATCH',
        ]);

        $this->actingAs($parent->project->owner)
            ->post(route('feature-requests.verifications.store', $followUp))
            ->assertRedirect(route('feature-requests.show', $followUp));

        $verification = $followUp->verifications()->sole();
        $this->assertSame(VerificationStatus::Passed, $verification->status);
        $this->assertNotNull($verification->finished_at);

        $this->assertSame($parent->project->source_path, $this->driver->copies[0]['source']);
        $workspaceId = $this->driver->copies[0]['workspace'];
        $this->assertSame('PARENT PATCH', $this->driver->files["{$workspaceId}:.builder/01.patch"]);
        $this->assertSame('FOLLOW-UP PATCH', $this->driver->files["{$workspaceId}:.builder/02.patch"]);

        $this->assertSame(
            ["Apply change #{$parent->id}", "Apply change #{$followUp->id}", 'Install', 'Tests', 'Lint'],
            array_column($verification->results, 'name'),
        );
        $this->assertSame([$workspaceId], $this->driver->destroyed);
    }

    public function test_every_check_runs_and_a_failing_one_fails_the_verification()
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command === ['php', 'artisan', 'test'] ? 1 : 0,
            output: $command === ['php', 'artisan', 'test'] ? "\e[31;1m1 failed\e[39;22m" : 'ok',
            errorOutput: '',
            durationMs: 10,
        );
        $request = FeatureRequest::factory()->generated()->create();

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $this->assertSame(['Tests', 'Lint'], array_column(array_slice($verification->results, 2), 'name'));
        $this->assertSame('1 failed', $verification->results[2]['output']);
    }

    public function test_a_failing_setup_step_stops_the_run_before_the_checks()
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command === ['composer', 'install'] ? 2 : 0,
            output: '',
            errorOutput: 'network down',
            durationMs: 10,
        );
        $request = FeatureRequest::factory()->generated()->create();

        $this->actingAs($request->project->owner)->post(route('feature-requests.verifications.store', $request));

        $verification = $request->verifications()->sole();
        $this->assertSame(VerificationStatus::Errored, $verification->status);
        $this->assertSame('A setup step failed, so the checks did not run.', $verification->error);
        $this->assertNotContains('Tests', array_column($verification->results, 'name'));
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
            'results' => [['name' => 'Tests', 'stage' => 'checks', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 5, 'output' => 'OK']],
        ]);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('verification.status', 'passed')
                ->where('verification.results.0.name', 'Tests'));
    }
}
