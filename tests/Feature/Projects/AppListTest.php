<?php

namespace Tests\Feature\Projects;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\TestObservation;
use App\Models\User;
use App\Models\Verification;
use App\Models\VisualEdit;
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

    public function test_the_app_worked_on_last_comes_first_even_when_the_work_was_a_design_edit()
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $asked = Project::factory()->for($user, 'owner')->create(['name' => 'Asked', 'created_at' => now()->subDays(5)]);
        FeatureRequest::factory()->for($asked)->create(['created_at' => now()->subDays(2)]);
        $edited = Project::factory()->for($user, 'owner')->create(['name' => 'Edited', 'created_at' => now()->subDays(5)]);
        VisualEdit::factory()->create(['project_id' => $edited->id, 'user_id' => $user->id, 'created_at' => now()->subDay()]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.name', 'Edited')
                ->where('projects.0.edited_at', now()->subDay()->toIso8601String())
                ->where('projects.1.name', 'Asked'));
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

    public function test_the_tests_a_change_still_waiting_added_are_not_counted_as_the_apps()
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $patch = implode("\n", [
            'diff --git a/tests/Feature/PlanTest.php b/tests/Feature/PlanTest.php',
            '--- a/tests/Feature/PlanTest.php',
            '+++ b/tests/Feature/PlanTest.php',
            '@@ -1,1 +1,2 @@',
            '+    public function test_plans_can_be_paused()',
            'diff --git a/tests/Feature/ClassTest.php b/tests/Feature/ClassTest.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/tests/Feature/ClassTest.php',
            '@@ -0,0 +1,1 @@',
            '+    public function test_classes_can_be_booked()',
        ]);
        $waiting = FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Generated, 'patch' => $patch]);
        $test = fn (string $id, string $file) => ['id' => $id, 'file' => $file, 'groups' => []];
        TestObservation::create(['project_id' => $project->id, 'feature_request_id' => $waiting->id, 'files' => [], 'tests' => [
            $test('Tests\\Feature\\PlanTest::test_plans_can_be_made', 'tests/Feature/PlanTest.php'),
            $test('Tests\\Feature\\PlanTest::test_plans_can_be_paused', 'tests/Feature/PlanTest.php'),
            $test('Tests\\Feature\\ClassTest::test_classes_can_be_booked', 'tests/Feature/ClassTest.php'),
            $test('Tests\\Unit\\ExampleTest::test_that_true_is_true', 'tests/Unit/ExampleTest.php'),
        ]]);

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.0.tests', 2));

        // Once kept, they are the app's.
        $waiting->update(['commit_sha' => str_repeat('a', 40), 'accepted_at' => now()]);

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.0.tests', 4));
    }

    public function test_each_app_shows_a_picture_of_its_front_page_from_its_latest_kept_or_waiting_change()
    {
        $owner = User::factory()->create();
        $pictured = Project::factory()->for($owner, 'owner')->create(['created_at' => now()->subDay()]);
        $waiting = Project::factory()->for($owner, 'owner')->create(['created_at' => now()->subDays(2)]);
        $shots = ['pages' => [['path' => '/teams', 'screen' => 'Teams'], ['path' => '/', 'screen' => 'Welcome']], 'shots' => [
            ['screen' => 'Teams', 'width' => 1280, 'path' => 'a.png'],
            ['screen' => 'Welcome', 'width' => 390, 'path' => 'b.png'],
            ['screen' => 'Welcome', 'width' => 1280, 'path' => 'c.png'],
        ]];
        $kept = FeatureRequest::factory()->for($pictured)->create(['commit_sha' => str_repeat('a', 40)]);
        $verification = Verification::factory()->for($kept)->create(['screens' => $shots]);
        // A change waiting for the owner is what "Try it" shows.
        $new = Verification::factory()->for(FeatureRequest::factory()->for($waiting)->create(['status' => FeatureRequestStatus::Generated, 'created_at' => now()->subDays(3)]))->create(['screens' => $shots]);
        // One the owner turned down is not their app.
        $turnedDown = Project::factory()->for($owner, 'owner')->create(['created_at' => now()->subDays(4)]);
        Verification::factory()->for(FeatureRequest::factory()->for($turnedDown)->create(['status' => FeatureRequestStatus::Generated, 'dismissed_at' => now(), 'created_at' => now()->subDays(4)]))->create(['screens' => $shots]);

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.picture', route('verifications.shots.show', [$verification, 2]))
                ->where('projects.1.picture', route('verifications.shots.show', [$new, 2]))
                ->where('projects.2.picture', null));
    }

    public function test_each_app_says_when_its_newest_change_is_being_made_or_stopped()
    {
        $owner = User::factory()->create();
        $made = fn (string $name, int $days) => Project::factory()->for($owner, 'owner')->create(['name' => $name, 'created_at' => now()->subDays($days)]);
        $ask = fn (Project $project, RunStatus $status, array $attributes = []) => Run::factory()->for(FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Generating, 'created_at' => $project->created_at, ...$attributes]))->create(['status' => $status]);

        $ask($made('Working', 1), RunStatus::Implementing);
        $ask($made('Stopped', 2), RunStatus::NeedsUserDecision);
        // Set aside by the owner, so it is nothing to act on.
        $ask($made('Set aside', 3), RunStatus::NeedsUserDecision, ['dismissed_at' => now()]);

        $this->actingAs($owner)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.now', 'working')
                ->where('projects.1.now', 'stopped')
                ->where('projects.2.now', null));
    }
}
