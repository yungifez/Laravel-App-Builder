<?php

namespace Tests\Feature\VisualEditing;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInLocalWorkspaces;
use Tests\Concerns\UsesReferenceSolutions;
use Tests\TestCase;

class PageConsistencyTest extends TestCase
{
    use BuildsInLocalWorkspaces, RefreshDatabase, UsesReferenceSolutions;

    public function test_the_owner_asks_for_the_page_they_are_looking_at_to_be_made_consistent()
    {
        $this->buildInLocalWorkspaces();
        $solutions = $this->useReferenceSolutions();
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create(['source_path' => "{$solutions}/source"]);
        config(['builder.design.consistency' => 'Tidy up :page.']);

        $response = $this->actingAs($owner)->post(route('page-consistency.store', $project), ['path' => '/login']);

        $request = $project->featureRequests()->sole();
        $response->assertRedirect(route('projects.show', ['project' => $project, 'change' => $request->uuid]));
        $this->assertSame('Tidy up /login.', $request->prompt);
        $this->assertSame($owner->id, $request->user_id);
        $this->assertNotNull($request->latestRun);
    }

    public function test_the_page_must_be_an_address_in_the_app()
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('page-consistency.store', $project), ['path' => 'https://example.com'])
            ->assertSessionHasErrors('path');

        $this->assertSame(0, $project->featureRequests()->count());
    }

    public function test_other_users_cannot_ask_for_changes_to_an_app()
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('page-consistency.store', $project), ['path' => '/'])
            ->assertForbidden();

        $this->assertSame(0, $project->featureRequests()->count());
    }
}
