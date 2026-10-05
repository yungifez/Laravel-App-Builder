<?php

namespace Tests\Feature\Features;

use App\Enums\ChecksStoppedBecause;
use App\Enums\VerificationStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Verification;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\UsesAcceptanceSuite;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class ChecksStoppedNextStepTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase, UsesAcceptanceSuite;

    protected const COMPOSER_PATCH = "diff --git a/composer.json b/composer.json\n--- a/composer.json\n+++ b/composer.json\n@@ -1 +1,2 @@\n {\n+\"require\": {\"acme/missing\": \"^9\"}\n";

    protected FakeWorkspaceDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ExecuteRun::class]);
        $this->driver = $this->fakeWorkspaces();
        $this->useAcceptanceSuite();

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [
                ['name' => 'Install', 'command' => ['composer', 'install'], 'timeout' => 600],
            ],
            'builder.verification.checks' => [
                ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300],
            ],
            'builder.verification.security.enabled' => false,
        ]);
    }

    public function test_a_change_its_app_could_not_install_is_made_again(): void
    {
        $this->failInstall();
        $change = FeatureRequest::factory()->generated()->create(['patch' => self::COMPOSER_PATCH]);

        $verification = $this->check($change);

        $this->assertSame(ChecksStoppedBecause::ChangeInstall, $verification->stopped_because);
        $this->assertSame(ChecksStoppedBecause::ChangeInstall->message(), $verification->error);
        $this->assertCanRetry($change, true);

        $this->post(route('feature-requests.retries.store', $change))->assertSessionHasNoErrors();
        $this->assertSame(1, FeatureRequest::query()->where('retry_of_id', $change->id)->count());
    }

    public function test_a_copy_that_could_not_be_set_up_is_checked_again_not_made_again(): void
    {
        $this->failInstall();
        $change = FeatureRequest::factory()->generated()->create();

        $verification = $this->check($change);

        $this->assertSame(ChecksStoppedBecause::Setup, $verification->stopped_because);
        $this->assertStringContainsString('This is our fault. Check again in a few minutes.', (string) $verification->error);
        $this->assertCanRetry($change, false);

        $this->post(route('feature-requests.retries.store', $change))->assertSessionHasErrors('retry');
        $this->assertSame(0, FeatureRequest::query()->where('retry_of_id', $change->id)->count());
    }

    public function test_a_change_that_does_not_apply_is_made_again_unless_it_was_kept_or_checked(): void
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command[0] === 'git' ? 1 : 0,
            output: '',
            errorOutput: 'patch does not apply',
            durationMs: 10,
        );
        $change = FeatureRequest::factory()->generated()->create();

        $verification = $this->check($change);

        $this->assertSame(ChecksStoppedBecause::DoesNotApply, $verification->stopped_because);
        $this->assertCanRetry($change, true);

        // Once kept, the change is the owner's: it is never made again.
        $change->update(['commit_sha' => str_repeat('a', 40), 'accepted_at' => now()]);
        $this->assertCanRetry($change, false);

        // Checks that ran and failed say nothing about making it again.
        $checked = FeatureRequest::factory()->generated()->create();
        Verification::factory()->for($checked)->create(['status' => VerificationStatus::Failed]);
        $this->assertCanRetry($checked, false);
    }

    protected function failInstall(): void
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command === ['composer', 'install'] ? 2 : 0,
            output: '',
            errorOutput: 'Your requirements could not be resolved.',
            durationMs: 10,
        );
    }

    protected function check(FeatureRequest $change): Verification
    {
        $this->actingAs($change->project->owner)->post(route('feature-requests.verifications.store', $change));

        return $change->verifications()->sole();
    }

    protected function assertCanRetry(FeatureRequest $change, bool $expected): void
    {
        $this->actingAs($change->project->owner)
            ->get(route('feature-requests.show', $change))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.can_retry', $expected)->etc());
    }
}
