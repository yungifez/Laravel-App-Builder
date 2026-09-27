<?php

namespace Tests\Feature\Features;

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DismissChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_marks_a_change_waiting_for_them_as_not_needed_and_brings_it_back()
    {
        $change = FeatureRequest::factory()->generated()->create();
        $owner = $change->project->owner;

        $this->actingAs($owner)
            ->get(route('projects.show', $change->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.0.state', 'waiting')
                ->where('changes.0.dismissable', true));

        $this->actingAs($owner)->post(route('feature-requests.dismissal.store', $change))->assertRedirect();

        $this->assertNotNull($change->refresh()->dismissed_at);
        $this->actingAs($owner)
            ->get(route('projects.show', $change->project))
            ->assertInertia(fn (Assert $page) => $page->where('changes.0.state', 'dismissed'));

        $this->actingAs($owner)->delete(route('feature-requests.dismissal.destroy', $change))->assertRedirect();

        $this->assertNull($change->refresh()->dismissed_at);
    }

    public function test_the_whole_ask_is_set_aside_from_any_of_its_follow_ups_and_its_work_stops()
    {
        $root = FeatureRequest::factory()->generated()->create();
        $followUp = FeatureRequest::factory()->create(['project_id' => $root->project_id, 'user_id' => $root->user_id, 'parent_id' => $root->id]);
        $run = Run::factory()->implementing()->create(['feature_request_id' => $followUp->id]);

        $this->actingAs($root->project->owner)->post(route('feature-requests.dismissal.store', $followUp));

        $this->assertNotNull($root->refresh()->dismissed_at);
        $this->assertContains($run->refresh()->status, [RunStatus::Cancelling, RunStatus::Cancelled]);
    }

    public function test_a_kept_change_cannot_be_set_aside()
    {
        $kept = FeatureRequest::factory()->generated()->create(['commit_sha' => str_repeat('b', 40), 'accepted_at' => now()]);

        $this->actingAs($kept->project->owner)
            ->get(route('projects.show', $kept->project))
            ->assertInertia(fn (Assert $page) => $page->where('changes.0.dismissable', false));

        $this->actingAs($kept->project->owner)
            ->post(route('feature-requests.dismissal.store', $kept))
            ->assertSessionHasErrors(['dismiss' => 'This is part of your app. Undo it instead.']);

        $this->assertNull($kept->refresh()->dismissed_at);
    }

    public function test_only_the_owner_can_set_a_change_aside()
    {
        $change = FeatureRequest::factory()->generated()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('feature-requests.dismissal.store', $change))
            ->assertForbidden();

        $this->assertNull($change->refresh()->dismissed_at);
    }
}
