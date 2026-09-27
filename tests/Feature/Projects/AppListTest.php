<?php

namespace Tests\Feature\Projects;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_worked_on_last_comes_first()
    {
        $owner = User::factory()->create();
        $old = Project::factory()->for($owner, 'owner')->create(['name' => 'Old', 'created_at' => now()->subDays(10)]);
        Project::factory()->for($owner, 'owner')->create(['name' => 'New', 'created_at' => now()->subDays(2)]);
        Project::factory()->for($owner, 'owner')->create(['name' => 'Untouched', 'created_at' => now()->subDays(5)]);
        FeatureRequest::factory()->create(['project_id' => $old->id, 'user_id' => $owner->id, 'created_at' => now()->subDay()]);

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.name', 'Old')
                ->where('projects.1.name', 'New')
                ->where('projects.2.name', 'Untouched'));
    }
}
