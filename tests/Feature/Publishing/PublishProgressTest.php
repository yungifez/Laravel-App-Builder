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
}
