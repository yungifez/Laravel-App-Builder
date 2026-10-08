<?php

namespace Tests\Feature\Features;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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

    public function test_users_can_add_a_project_inside_an_allowed_root()
    {
        $root = $this->makeRoot();
        mkdir("{$root}/acme");
        config(['builder.projects.roots' => [$root]]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Acme',
            'source_path' => "{$root}/acme/../acme",
        ]);

        $project = $user->projects()->sole();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame(realpath("{$root}/acme"), $project->source_path);
    }

    public function test_a_project_outside_the_allowed_roots_is_refused()
    {
        $root = $this->makeRoot();
        config(['builder.projects.roots' => [$root]]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), ['name' => 'Etc', 'source_path' => '/etc'])
            ->assertSessionHasErrors('source_path');

        $this->assertDatabaseEmpty('projects');
    }

    public function test_no_project_is_accepted_without_allowed_roots()
    {
        $root = $this->makeRoot();
        config(['builder.projects.roots' => []]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), ['name' => 'Acme', 'source_path' => $root])
            ->assertSessionHasErrors('source_path');
    }

    public function test_a_project_cannot_contain_the_builder_itself()
    {
        config(['builder.projects.roots' => ['/']]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), ['name' => 'Builder', 'source_path' => base_path()])
            ->assertSessionHasErrors('source_path');

        $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), ['name' => 'Everything', 'source_path' => '/'])
            ->assertSessionHasErrors('source_path');
    }

    public function test_a_symlink_out_of_an_allowed_root_is_refused()
    {
        $root = $this->makeRoot();
        symlink('/etc', "{$root}/escape");
        config(['builder.projects.roots' => [$root]]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), ['name' => 'Escape', 'source_path' => "{$root}/escape"])
            ->assertSessionHasErrors('source_path');
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

    /**
     * Make an empty directory, outside the repository, removed after the test.
     */
    protected function makeRoot(): string
    {
        $root = sys_get_temp_dir().'/builder-project-roots-'.Str::random(8);
        mkdir($root);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

        return $root;
    }
}
