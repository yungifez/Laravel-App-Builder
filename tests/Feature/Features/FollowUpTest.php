<?php

namespace Tests\Feature\Features;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Features\RetryFeatureRequest;
use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Building the change is not what these tests are about.
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle'));
    }

    public function test_when_a_follow_up_stops_the_last_passed_change_before_it_can_still_be_kept()
    {
        $passed = FeatureRequest::factory()->generated()->create();
        Run::factory()->create(['feature_request_id' => $passed->id, 'status' => RunStatus::Completed]);
        $stopped = FeatureRequest::factory()->generated()->create(['project_id' => $passed->project_id, 'user_id' => $passed->user_id, 'parent_id' => $passed->id]);
        Run::factory()->create(['feature_request_id' => $stopped->id, 'status' => RunStatus::Failed]);
        $latest = FeatureRequest::factory()->create(['project_id' => $passed->project_id, 'user_id' => $passed->user_id, 'parent_id' => $stopped->id, 'status' => FeatureRequestStatus::Failed]);
        $keep = fn (FeatureRequest $request) => app(DescribeFeatureRequest::class)->handle($request->refresh())['featureRequest']['keep_earlier'];

        // The stopped change in between is passed over.
        $this->assertSame($passed->uuid, $keep($latest));

        // Once it is kept, there is nothing earlier left to keep.
        $passed->update(['commit_sha' => str_repeat('a', 40)]);
        $this->assertNull($keep($latest));
    }

    public function test_a_message_about_a_generated_change_continues_it_in_the_same_chat()
    {
        $parent = FeatureRequest::factory()->generated()->create(['summary' => 'Owners can invite people.']);

        $response = $this->actingAs($parent->project->owner)
            ->post(route('feature-requests.follow-ups.store', $parent), ['prompt' => 'Also let admins invite people.']);

        $followUp = $parent->followUps()->sole();
        $response->assertRedirect(route('projects.show', ['project' => $parent->project, 'change' => $followUp->uuid]));
        $this->assertSame('Also let admins invite people.', $followUp->prompt);
        $this->assertNull($followUp->target_step);
        $this->assertSame(FeatureRequestStatus::Generating, $followUp->status);
        $this->assertSame($parent->base_revision, $followUp->base_revision);

        $this->get(route('projects.show', ['project' => $parent->project, 'change' => $followUp->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('change.earlier.0.id', $parent->uuid)
                ->where('change.earlier.0.summary', 'Owners can invite people.')
                ->where('change.featureRequest.can_continue', false)
                ->has('changes', 1));
    }

    public function test_a_new_ask_builds_on_the_newest_change_the_owner_has_not_kept_yet()
    {
        $project = Project::factory()->create();
        FeatureRequest::factory()->for($project)->generated()->create();
        $newest = FeatureRequest::factory()->for($project)->generated()->create(['base_revision' => 'abc123']);

        $response = $this->actingAs($project->owner)
            ->post(route('feature-requests.store', $project), ['prompt' => 'Show places left on each class.']);

        $ask = $newest->followUps()->sole();
        $response->assertRedirect(route('projects.show', ['project' => $project, 'change' => $ask->uuid]));
        $this->assertSame('Show places left on each class.', $ask->prompt);
        $this->assertSame('abc123', $ask->base_revision);
        $this->assertSame(3, $project->featureRequests()->count());
    }

    public function test_a_new_ask_starts_from_the_kept_app_when_no_change_waits_to_be_tried()
    {
        $project = Project::factory()->create();
        FeatureRequest::factory()->for($project)->generated()->create(['commit_sha' => 'def456', 'accepted_at' => now()]);
        FeatureRequest::factory()->for($project)->generated()->create(['reverted_at' => now()]);
        FeatureRequest::factory()->for($project)->generated()->create(['dismissed_at' => now()]);
        FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Failed]);

        $this->actingAs($project->owner)
            ->post(route('feature-requests.store', $project), ['prompt' => 'Show places left on each class.']);

        $ask = $project->featureRequests()->latest('id')->first();
        $this->assertSame('Show places left on each class.', $ask->prompt);
        $this->assertNull($ask->parent_id);
    }

    public function test_a_change_with_nothing_to_build_on_cannot_be_continued()
    {
        $failed = FeatureRequest::factory()->create(['status' => FeatureRequestStatus::Failed]);
        $undone = FeatureRequest::factory()->generated()->create(['reverted_at' => now()]);

        foreach ([$failed, $undone] as $request) {
            $this->actingAs($request->project->owner)
                ->post(route('feature-requests.follow-ups.store', $request), ['prompt' => 'More please'])
                ->assertSessionHasErrors('prompt');

            $this->assertSame(0, $request->followUps()->count());
        }
    }

    public function test_other_users_cannot_continue_a_change()
    {
        $parent = FeatureRequest::factory()->generated()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('feature-requests.follow-ups.store', $parent), ['prompt' => 'More please'])
            ->assertForbidden();

        $this->assertSame(0, $parent->followUps()->count());
    }

    public function test_a_follow_up_is_tried_again_in_the_same_chat()
    {
        $parent = FeatureRequest::factory()->generated()->create();
        $followUp = $parent->followUps()->create([
            'user_id' => $parent->user_id,
            'project_id' => $parent->project_id,
            'prompt' => 'Also let admins invite people.',
            'status' => FeatureRequestStatus::Failed,
            'generator' => $parent->generator,
            'base_revision' => $parent->base_revision,
        ]);

        $retry = app(RetryFeatureRequest::class)->handle($followUp, $parent->project->owner);

        $this->assertSame($parent->id, $retry->parent_id);
        $this->assertSame($followUp->id, $retry->retry_of_id);
    }
}
