<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenameProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_renames_their_app()
    {
        $project = Project::factory()->create(['name' => 'Acme Shop']);

        $this->actingAs($project->owner)
            ->from(route('projects.show', $project))
            ->patch(route('projects.name.update', $project), ['name' => '  Acme   Bakery '])
            ->assertRedirect(route('projects.show', $project));

        $this->assertSame('Acme Bakery', $project->refresh()->name);
    }

    public function test_the_published_app_keeps_where_it_lives_after_a_rename()
    {
        $project = Project::factory()->create(['name' => 'Acme Shop', 'host' => 'laravel_cloud', 'host_state' => ['repository' => 'org/acme-shop-1', 'application' => 'app-1']]);

        $this->actingAs($project->owner)->patch(route('projects.name.update', $project), ['name' => 'Acme Bakery']);

        $this->assertSame(['repository' => 'org/acme-shop-1', 'application' => 'app-1'], $project->refresh()->host_state);
    }

    public function test_a_name_is_needed()
    {
        $project = Project::factory()->create(['name' => 'Acme Shop']);

        $this->actingAs($project->owner)
            ->patch(route('projects.name.update', $project), ['name' => '   '])
            ->assertSessionHasErrors(['name' => 'Give your app a name.']);

        $this->assertSame('Acme Shop', $project->refresh()->name);
    }

    public function test_only_the_owner_can_rename_an_app()
    {
        $project = Project::factory()->create(['name' => 'Acme Shop']);

        $this->actingAs(User::factory()->create())
            ->patch(route('projects.name.update', $project), ['name' => 'Mine now'])
            ->assertForbidden();

        $this->assertSame('Acme Shop', $project->refresh()->name);
    }
}
