<?php

namespace Tests\Feature\Features;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('projects.index'))->assertRedirect(route('login'));
    }

    public function test_users_see_only_their_own_projects()
    {
        $user = User::factory()->create();
        Project::factory()->for($user, 'owner')->create(['name' => 'Mine']);
        Project::factory()->create(['name' => 'Theirs']);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Index')
                ->has('projects', 1)
                ->where('projects.0.name', 'Mine'));
    }

    public function test_users_can_add_a_project()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Acme',
            'source_path' => '/srv/acme',
        ]);

        $project = $user->projects()->sole();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame('/srv/acme', $project->source_path);
    }

    public function test_a_project_needs_a_name_and_source_path()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), [])
            ->assertSessionHasErrors(['name', 'source_path']);
    }

    public function test_users_cannot_view_someone_elses_project()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('projects.show', Project::factory()->create()))
            ->assertForbidden();
    }

    public function test_the_dashboard_counts_the_users_projects()
    {
        $user = User::factory()->create();
        Project::factory()->for($user, 'owner')->count(2)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('projectCount', 2));
    }
}
