<?php

namespace Tests\Feature\Projects;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\TestObservation;
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

    public function test_each_app_says_how_many_of_its_own_tests_guard_it()
    {
        $owner = User::factory()->create();
        $checked = Project::factory()->for($owner, 'owner')->create(['name' => 'Checked', 'created_at' => now()->subDay()]);
        Project::factory()->for($owner, 'owner')->create(['name' => 'Unchecked', 'created_at' => now()->subDays(2)]);
        TestObservation::create(['project_id' => $checked->id, 'tests' => [
            ['id' => 'Tests\\Feature\\PlanTest::test_one', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
            ['id' => 'Tests\\Feature\\PlanTest::test_two', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
        ], 'files' => []]);

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.tests', 2)
                ->where('projects.1.tests', null));
    }
}
