<?php

namespace Tests\Feature\Publishing;

use App\Actions\Runs\StartRun;
use App\Enums\DeploymentStatus;
use App\Enums\FeatureRequestStatus;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CheckFixTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Deployment $failed;

    protected function setUp(): void
    {
        parent::setUp();

        // Building the fix is not what these tests are about.
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle'));

        $this->project = Project::factory()->create();
        $this->failed = Deployment::factory()->for($this->project)->create([
            'user_id' => $this->project->user_id,
            'status' => DeploymentStatus::Failed,
            'checks' => [
                ['name' => 'Install', 'passed' => true],
                ['name' => 'Tests', 'passed' => false, 'output' => "FAILED  Tests\\Feature\\CartTest > it totals the cart\nExpected 12 but got 10 for owner jane@example.com"],
                ['name' => 'Code style', 'passed' => true],
            ],
        ]);
    }

    public function test_one_click_asks_for_a_fix_in_plain_words_and_gives_the_builder_what_failed()
    {
        $response = $this->actingAs($this->project->owner)->post(route('check-fixes.store', $this->project));

        $fix = FeatureRequest::sole();
        $response->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->uuid]));
        $this->assertSame('Fix what stopped my app going online.', $fix->prompt);
        $this->assertSame(FeatureRequestStatus::Generating, $fix->status);
        $this->assertNull($fix->experiment_id);
        $this->assertSame($this->failed->id, $fix->failed_checks['deployment_id'] ?? null);

        $instructions = $fix->instructions();
        $this->assertStringContainsString('- Tests failed.', $instructions);
        $this->assertStringContainsString('CartTest > it totals the cart', $instructions);
        $this->assertStringContainsString('Do not skip, weaken or delete a check or a test', $instructions);
        $this->assertStringNotContainsString('Code style', $instructions);
        $this->assertStringNotContainsString('jane@example.com', $instructions);
    }

    public function test_a_second_click_opens_the_fix_already_asked_for()
    {
        $this->actingAs($this->project->owner)->post(route('check-fixes.store', $this->project));
        $first = FeatureRequest::sole();

        $this->actingAs($this->project->owner)
            ->post(route('check-fixes.store', $this->project))
            ->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $first->uuid]));

        $this->assertSame(1, FeatureRequest::count());
    }

    public function test_there_is_nothing_to_fix_once_a_newer_publish_started()
    {
        Deployment::factory()->for($this->project)->create(['user_id' => $this->project->user_id, 'status' => DeploymentStatus::Checking, 'checks' => []]);

        $this->actingAs($this->project->owner)
            ->post(route('check-fixes.store', $this->project))
            ->assertSessionHasErrors(['fix' => 'No check stopped your app going online.']);

        $this->assertSame(0, FeatureRequest::count());
    }

    public function test_only_people_who_may_ask_for_changes_can_ask_for_the_fix()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('check-fixes.store', $this->project))
            ->assertForbidden();

        $this->assertSame(0, FeatureRequest::count());
    }
}
