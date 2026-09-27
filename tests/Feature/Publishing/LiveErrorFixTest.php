<?php

namespace Tests\Feature\Publishing;

use App\Actions\Runs\StartRun;
use App\Enums\DeploymentStatus;
use App\Enums\FeatureRequestStatus;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class LiveErrorFixTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Deployment $online;

    protected function setUp(): void
    {
        parent::setUp();

        // Building the fix is not what these tests are about.
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle'));

        $this->project = Project::factory()->create();
        $this->online = Deployment::factory()->for($this->project)->create([
            'user_id' => $this->project->user_id,
            'status' => DeploymentStatus::Published,
            'live_errors' => [
                ['class' => 'ErrorException', 'message' => 'Undefined array key 12', 'count' => 4, 'last_at' => '2026-09-27T10:00:00Z'],
                ['class' => null, 'message' => 'Payment gateway timed out', 'count' => 1, 'last_at' => '2026-09-27T10:01:00Z'],
            ],
        ]);
    }

    public function test_one_click_asks_for_a_fix_in_plain_words_and_gives_the_builder_the_errors()
    {
        $response = $this->actingAs($this->project->owner)->post(route('live-error-fixes.store', $this->project));

        $fix = FeatureRequest::sole();
        $response->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->id]));
        $this->assertSame('Fix the problems people ran into in my app online.', $fix->prompt);
        $this->assertSame(FeatureRequestStatus::Generating, $fix->status);
        $this->assertNull($fix->experiment_id);
        $this->assertSame($this->online->id, $fix->live_errors['deployment_id']);

        $instructions = $fix->instructions();
        $this->assertStringContainsString('- ErrorException: Undefined array key 12 (4 times)', $instructions);
        $this->assertStringContainsString('- Payment gateway timed out (once)', $instructions);
        $this->assertStringNotContainsString('last_at', $instructions);
    }

    public function test_a_second_click_opens_the_fix_already_asked_for()
    {
        $this->actingAs($this->project->owner)->post(route('live-error-fixes.store', $this->project));
        $first = FeatureRequest::sole();

        $this->actingAs($this->project->owner)
            ->post(route('live-error-fixes.store', $this->project))
            ->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $first->id]));

        $this->assertSame(1, FeatureRequest::count());
    }

    public function test_a_fix_set_aside_or_stopped_can_be_asked_for_again()
    {
        $this->actingAs($this->project->owner)->post(route('live-error-fixes.store', $this->project));
        FeatureRequest::sole()->update(['status' => FeatureRequestStatus::Failed]);

        $this->actingAs($this->project->owner)->post(route('live-error-fixes.store', $this->project));

        $this->assertSame(2, FeatureRequest::count());
    }

    public function test_there_is_nothing_to_fix_when_the_app_online_has_no_errors()
    {
        $this->online->update(['live_errors' => []]);

        $this->actingAs($this->project->owner)
            ->post(route('live-error-fixes.store', $this->project))
            ->assertSessionHasErrors(['fix' => 'Your app online has not run into problems.']);

        $this->assertSame(0, FeatureRequest::count());
    }

    public function test_only_the_owner_can_ask_for_a_fix()
    {
        $this->actingAs(Project::factory()->create()->owner)
            ->post(route('live-error-fixes.store', $this->project))
            ->assertForbidden();

        $this->assertSame(0, FeatureRequest::count());
    }
}
