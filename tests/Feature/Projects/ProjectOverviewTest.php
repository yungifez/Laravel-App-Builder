<?php

namespace Tests\Feature\Projects;

use App\Enums\DeploymentStatus;
use App\Enums\FeatureRequestStatus;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_ask_is_listed_once_with_where_it_stands()
    {
        $project = Project::factory()->create();
        $request = fn (array $attributes = []) => FeatureRequest::factory()->for($project)->create($attributes);

        $stopped = $request(['status' => FeatureRequestStatus::Failed]);
        $working = $request();
        $waiting = $request(['status' => FeatureRequestStatus::Generated]);
        $undone = $request(['status' => FeatureRequestStatus::Generated, 'commit_sha' => 'a', 'accepted_at' => now(), 'reverted_at' => now()]);

        // The owner changed a step and kept that follow-up: the ask is kept,
        // and it opens on the kept follow-up, not on its waiting parent.
        $parent = $request(['status' => FeatureRequestStatus::Generated]);
        $keptChild = $request(['parent_id' => $parent->id, 'status' => FeatureRequestStatus::Generated, 'commit_sha' => 'b', 'accepted_at' => now()]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('changes', 5)
                ->where('changes.0.id', $keptChild->id)
                ->where('changes.0.state', 'kept')
                ->where('changes.0.prompt', $parent->prompt)
                ->where('changes.1.id', $undone->id)
                ->where('changes.1.state', 'undone')
                ->where('changes.2.id', $waiting->id)
                ->where('changes.2.state', 'waiting')
                ->where('changes.3.id', $working->id)
                ->where('changes.3.state', 'working')
                ->where('changes.4.id', $stopped->id)
                ->where('changes.4.state', 'stopped'));
    }

    public function test_the_apps_list_says_what_waits_and_when_it_went_live()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        FeatureRequest::factory()->for($project)->count(2)->create(['status' => FeatureRequestStatus::Generated]);
        FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Generated, 'commit_sha' => 'a', 'accepted_at' => now()]);
        Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Published, 'finished_at' => now()]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.waiting', 2)
                ->whereType('projects.0.published_at', 'string')
                ->whereType('projects.0.changed_at', 'string')
                ->missing('projects.0.source_path'));
    }

    public function test_an_app_that_never_went_live_says_so()
    {
        $user = User::factory()->create();
        Project::factory()->for($user, 'owner')->create();

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.waiting', 0)
                ->where('projects.0.published_at', null)
                ->where('projects.0.changed_at', null));
    }
}
