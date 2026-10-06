<?php

namespace Tests\Feature\Publishing;

use App\Actions\Publishing\CheckDeployment;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A publish whose job died part way would look busy for ever. Past the
 * limit it ends the way its own job ends on a failure, with a next step.
 */
class ReconcileDeploymentsTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.publishing.stalled_minutes' => 70]);
        $this->project = Project::factory()->create(['deploy_remote' => 'https://git.example.com/acme.git', 'live_url' => 'https://shop.example.com']);
    }

    public function test_a_publish_stalled_before_or_while_sending_ends_with_try_again()
    {
        $checking = $this->deployment(DeploymentStatus::Checking, 71);
        $pushing = $this->deployment(DeploymentStatus::Pushing, 71);

        $this->artisan('publishing:reconcile')->assertSuccessful();

        foreach ([$checking->refresh(), $pushing->refresh()] as $deployment) {
            $this->assertSame(DeploymentStatus::Failed, $deployment->status);
            $this->assertSame('This is our fault: publishing stopped before it finished. Your app online may not have changed. Try again.', $deployment->error);
            $this->assertSame('ours', $deployment->error_cause);
            $this->assertStringContainsString('70 minutes', (string) $deployment->error_details);
            $this->assertNotNull($deployment->finished_at);
        }
    }

    public function test_a_publish_stalled_while_confirming_ends_with_checking_again()
    {
        $confirming = $this->deployment(DeploymentStatus::Confirming, 71);

        $this->artisan('publishing:reconcile')->assertSuccessful();

        $confirming->refresh();
        $this->assertSame(DeploymentStatus::NeedsAttention, $confirming->status);
        $this->assertSame('This is our fault: your hosting has the new version, but I could not check that the app is online. Check again.', $confirming->error);
        $this->assertTrue(CheckDeployment::checkable($confirming));
    }

    public function test_a_publish_still_within_the_limit_or_already_finished_is_left_alone()
    {
        $working = $this->deployment(DeploymentStatus::Checking, 69);
        $published = $this->deployment(DeploymentStatus::Published, 600);

        $this->artisan('publishing:reconcile')->assertSuccessful();

        $this->assertSame(DeploymentStatus::Checking, $working->refresh()->status);
        $this->assertNull($working->error);
        $this->assertSame(DeploymentStatus::Published, $published->refresh()->status);
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn (Event $event) => str_contains((string) $event->command, 'publishing:reconcile')));
    }

    /**
     * Make a publish that last changed some minutes ago.
     */
    protected function deployment(DeploymentStatus $status, int $minutesAgo): Deployment
    {
        $deployment = Deployment::factory()->for($this->project)->create(['user_id' => $this->project->user_id, 'status' => $status]);
        $deployment->timestamps = false;
        $deployment->forceFill(['updated_at' => now()->subMinutes($minutesAgo)])->save();

        return $deployment;
    }
}
