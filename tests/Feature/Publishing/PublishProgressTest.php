<?php

namespace Tests\Feature\Publishing;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublishProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_sees_which_check_runs_before_the_app_goes_online()
    {
        config(['builder.verification.setup' => [['name' => 'Install PHP dependencies']], 'builder.verification.checks' => [['name' => 'Tests'], ['name' => 'Static analysis']]]);
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->for($project)->create(['user_id' => $project->user_id, 'checks' => [['name' => 'Install PHP dependencies', 'passed' => true]]]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.deployments.0.doing', 'Running your app\'s tests, check 1 of 2'));

        $deployment->update(['status' => DeploymentStatus::Published]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.deployments.0.doing', null));
    }

    public function test_the_owner_is_told_why_a_first_publish_takes_long()
    {
        $project = Project::factory()->create();
        $deployment = Deployment::factory()->for($project)->create(['user_id' => $project->user_id, 'status' => DeploymentStatus::Pushing, 'host_status' => 'making_server']);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.deployments.0.doing', 'Getting a new server ready for your app. This takes about 10 minutes'));

        $deployment->update(['host_status' => 'setting_up']);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.deployments.0.doing', 'Setting up your app online for the first time. This takes a few minutes'));

        // A host's own words for a release are not shown to the owner.
        $deployment->update(['host_status' => 'deploying']);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.deployments.0.doing', null));
    }
}
