<?php

namespace Tests\Feature\Previews;

use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PreviewNoLongerFitsTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ExecuteRun::class]);
        $this->driver = $this->fakeWorkspaces();

        config([
            'builder.preview.workspace_driver' => 'fake',
            'builder.preview.domain' => 'preview.test',
            'builder.preview.listen_host' => '127.0.0.1',
            'builder.preview.public_port' => null,
            'builder.preview.setup' => [
                ['name' => 'Install', 'command' => ['composer', 'install'], 'timeout' => 600],
            ],
        ]);
    }

    public function test_a_change_that_no_longer_fits_is_made_again_instead_of_tried_again(): void
    {
        $this->failing(['git', 'apply']);
        $change = FeatureRequest::factory()->generated()->create(['patch' => 'PATCH']);

        $this->try($change);

        $this->assertTrue($change->previews()->sole()->no_longer_fits);
        $this->assertNextStep($change, noLongerFits: true, canRetry: true);

        $this->post(route('feature-requests.retries.store', $change))->assertSessionHasNoErrors();
        $this->assertSame(1, FeatureRequest::query()->where('retry_of_id', $change->id)->count());
    }

    public function test_a_copy_that_could_not_be_set_up_is_tried_again_not_made_again(): void
    {
        $this->failing(['composer', 'install']);
        $change = FeatureRequest::factory()->generated()->create(['patch' => 'PATCH']);

        $this->try($change);

        $this->assertFalse($change->previews()->sole()->no_longer_fits);
        $this->assertNextStep($change, noLongerFits: false, canRetry: false);
    }

    public function test_a_kept_change_is_never_made_again(): void
    {
        $this->failing(['git', 'apply']);
        $change = FeatureRequest::factory()->generated()->create(['patch' => 'PATCH']);
        $this->try($change);

        $change->update(['commit_sha' => str_repeat('a', 40), 'accepted_at' => now()]);

        $this->assertNextStep($change, noLongerFits: true, canRetry: false);
        $this->post(route('feature-requests.retries.store', $change))->assertSessionHasErrors('retry');
    }

    /**
     * Fail the command that starts with the given words.
     *
     * @param  list<string>  $words
     */
    protected function failing(array $words): void
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: array_slice($command, 0, count($words)) === $words ? 1 : 0,
            output: '',
            errorOutput: 'error: patch failed: app/Models/Team.php:12',
            durationMs: 5,
        );
    }

    protected function try(FeatureRequest $change): void
    {
        $this->actingAs($change->project->owner)->post(route('feature-requests.previews.store', $change));
    }

    protected function assertNextStep(FeatureRequest $change, bool $noLongerFits, bool $canRetry): void
    {
        $this->actingAs($change->project->owner)
            ->get(route('feature-requests.show', $change))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.no_longer_fits', $noLongerFits)
                ->where('featureRequest.can_retry', $canRetry)
                ->where('featureRequest.made_again_only', $canRetry)
                ->etc());
    }
}
