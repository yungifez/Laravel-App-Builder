<?php

namespace Tests\Feature\Projects;

use App\Enums\PreviewStatus;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_name_an_app_and_its_changes_by_uuid_and_a_number_finds_nothing()
    {
        $project = Project::factory()->create();
        $change = FeatureRequest::factory()->for($project)->create();

        $this->assertTrue(str_contains(route('projects.show', $project), $project->uuid));

        $this->actingAs($project->owner)
            ->get(route('projects.show', ['project' => $project, 'change' => $change->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.id', $project->uuid)
                ->where('change.featureRequest.id', $change->uuid));

        $this->actingAs($project->owner)
            ->get(route('projects.understanding.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('project.id', $project->uuid));

        $this->actingAs($project->owner)->get("/projects/{$project->id}")->assertNotFound();
        $this->actingAs($project->owner)->get("/feature-requests/{$change->id}")->assertNotFound();
        $this->actingAs($project->owner)
            ->get(route('projects.show', ['project' => $project, 'change' => $change->id]))
            ->assertNotFound();
    }

    public function test_a_change_of_another_app_is_not_opened_through_this_one()
    {
        $project = Project::factory()->create();
        $theirs = FeatureRequest::factory()->create();

        $this->actingAs($project->owner)
            ->get(route('projects.show', ['project' => $project, 'change' => $theirs->uuid]))
            ->assertNotFound();
    }

    public function test_a_design_change_names_its_preview_by_uuid()
    {
        $project = Project::factory()->create();
        $preview = Preview::factory()->create(['feature_request_id' => null, 'project_id' => $project->id, 'editable' => true, 'status' => PreviewStatus::Ready]);
        $edit = fn (int|string $preview) => $this->actingAs($project->owner)->postJson(route('theme-colors.store', $project), [
            'preview' => $preview, 'mode' => 'light', 'token' => 'primary', 'color' => '#000000', 'revision' => str_repeat('a', 40),
        ]);

        $edit($preview->id)->assertJsonValidationErrors('preview');
        $edit($preview->uuid)->assertJsonMissingValidationErrors('preview');
    }
}
