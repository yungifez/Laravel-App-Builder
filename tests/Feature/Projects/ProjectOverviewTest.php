<?php

namespace Tests\Feature\Projects;

use App\Enums\DeploymentStatus;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\VerificationStatus;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_ask_is_listed_once_with_where_it_stands()
    {
        $project = Project::factory()->create();
        $request = fn (array $attributes = []) => FeatureRequest::factory()->for($project)->create($attributes);

        $stopped = $request(['status' => FeatureRequestStatus::Failed]);
        $working = $request();
        $waiting = $request(['status' => FeatureRequestStatus::Generated]);
        $undone = $request(['status' => FeatureRequestStatus::Generated, 'commit_sha' => 'a', 'accepted_at' => now(), 'reverted_at' => now()]);

        // The owner changed a step and kept that follow-up: the ask is kept,
        // and it opens on the kept follow-up, not on its waiting parent.
        $parent = $request(['status' => FeatureRequestStatus::Generated]);
        $keptChild = $request(['parent_id' => $parent->id, 'status' => FeatureRequestStatus::Generated, 'commit_sha' => 'b', 'accepted_at' => now()]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('changes', 5)
                ->where('changes.0.id', $keptChild->uuid)
                ->where('changes.0.state', 'kept')
                ->where('changes.0.prompt', $parent->prompt)
                ->where('changes.1.id', $undone->uuid)
                ->where('changes.1.state', 'undone')
                ->where('changes.2.id', $waiting->uuid)
                ->where('changes.2.state', 'waiting')
                ->where('changes.2.asks', false)
                ->where('changes.2.question', null)
                ->where('changes.3.id', $working->uuid)
                ->where('changes.3.state', 'working')
                ->where('changes.4.id', $stopped->uuid)
                ->where('changes.4.state', 'stopped'));
    }

    public function test_a_change_waiting_on_a_question_asks_for_an_answer()
    {
        $project = Project::factory()->create();
        $asking = FeatureRequest::factory()->for($project)->create();
        Run::factory()->for($asking)->create([
            'status' => RunStatus::NeedsUserDecision,
            'question' => ['text' => 'Who can invite?', 'why' => '', 'options' => ['Owners', 'Everyone'], 'recommended' => null],
        ]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.0.state', 'waiting')
                ->where('changes.0.asks', true)
                ->where('changes.0.question', 'Who can invite?'));
    }

    public function test_a_follow_up_that_failed_leaves_the_change_it_built_on_to_try()
    {
        $project = Project::factory()->create();
        $first = FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Generated]);
        $frontPage = FeatureRequest::factory()->for($project)->create(['parent_id' => $first->id, 'status' => FeatureRequestStatus::Generated]);
        FeatureRequest::factory()->for($project)->create(['parent_id' => $frontPage->id, 'status' => FeatureRequestStatus::Failed]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('changes', 1)
                ->where('changes.0.id', $frontPage->uuid)
                ->where('changes.0.state', 'waiting')
                ->where('changes.0.prompt', $first->prompt));
    }

    public function test_a_change_to_try_says_how_many_of_its_tests_fail_without_it()
    {
        $project = Project::factory()->create();
        $patch = implode("\n", [
            'diff --git a/tests/Feature/ClassTest.php b/tests/Feature/ClassTest.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/tests/Feature/ClassTest.php',
            '@@ -0,0 +1,2 @@',
            '+    public function test_classes_show_places_left()',
            '+    public function test_the_page_loads()',
        ]);
        $waiting = FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Generated, 'patch' => $patch]);
        $test = fn (string $name, string $without) => ['file' => 'tests/Feature/ClassTest.php', 'name' => $name, 'without_change' => $without];
        // Only a test that fails without the change shows it works.
        Verification::factory()->for($waiting)->create(['evidence' => ['new_tests' => [
            $test('test_classes_show_places_left', 'failed'),
            $test('test_the_page_loads', 'passed'),
        ]]]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('changes.0.proved', 1)->where('changes.0.passing', 0));

        // None seen to fail without it: its tests are said to pass, once
        // every check passed, but not to prove it.
        $waiting->verifications()->delete();
        $verification = Verification::factory()->for($waiting)->create(['evidence' => ['new_tests' => []]]);

        $this->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('changes.0.proved', 0)->where('changes.0.passing', 0));

        $verification->update(['status' => VerificationStatus::Unverified]);

        $this->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('changes.0.proved', 0)->where('changes.0.passing', 2));
    }

    public function test_a_change_tried_again_is_listed_once_as_its_newest_try()
    {
        $project = Project::factory()->create();
        $first = FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Failed]);
        $second = FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Failed, 'retry_of_id' => $first->id]);
        $third = FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Generated, 'retry_of_id' => $second->id]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('changes', 1)
                ->where('changes.0.id', $third->uuid)
                ->where('changes.0.state', 'waiting'));
    }

    public function test_a_change_that_could_not_finish_is_not_still_being_worked_on()
    {
        $project = Project::factory()->create();
        $stuck = FeatureRequest::factory()->for($project)->create();
        Run::factory()->for($stuck)->create([
            'status' => RunStatus::NeedsUserDecision,
            'error' => 'The run finished without changing the project.',
        ]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.0.state', 'stopped')
                ->where('changes.0.asks', false));
    }

    public function test_a_change_waiting_on_a_proposal_from_the_checks_asks_for_an_answer()
    {
        $project = Project::factory()->create();
        $proposing = FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Generated]);
        Run::factory()->for($proposing)->create(['status' => RunStatus::NeedsUserDecision, 'stop_reason' => StopReason::FindingProposed]);
        $proposal = $proposing->findingProposals()->create(['kind' => 'owner_unchecked', 'identity' => 'owner_unchecked|App\Models\Item', 'reason' => 'Items are shared by the household.']);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.0.state', 'waiting')
                ->where('changes.0.asks', true));

        // Once answered, the run's own stop stands again.
        $proposal->update(['agreed' => true]);

        $this->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.0.state', 'stopped')
                ->where('changes.0.asks', false));
    }

    public function test_a_proposal_while_the_change_is_still_being_made_asks_for_an_answer()
    {
        $project = Project::factory()->create();
        $proposing = FeatureRequest::factory()->for($project)->create();
        Run::factory()->for($proposing)->create(['status' => RunStatus::NeedsUserDecision, 'stop_reason' => StopReason::FindingProposed]);
        $proposing->findingProposals()->create(['kind' => 'owner_unchecked', 'identity' => 'owner_unchecked|App\Models\Item', 'reason' => 'Items are shared by the household.']);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.0.state', 'waiting')
                ->where('changes.0.asks', true));
    }

    public function test_a_made_change_whose_checks_stopped_is_stopped_not_asking()
    {
        $project = Project::factory()->create();
        $stopped = FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Generated]);
        Run::factory()->for($stopped)->create(['status' => RunStatus::NeedsUserDecision, 'stop_reason' => StopReason::VerificationInterrupted]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.0.state', 'stopped')
                ->where('changes.0.asks', false));
    }

    public function test_the_app_page_shows_the_running_app_next_to_the_conversation()
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('preview', null));

        // A copy made to try one change is not the app itself.
        Preview::factory()->ready()->create(['project_id' => $project->id]);
        $running = Preview::factory()->editable()->ready()->create(['project_id' => $project->id]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.id', $running->uuid)
                ->where('preview.status', 'ready')
                ->where('preview.updating', false));
    }

    public function test_only_an_app_brought_in_says_where_it_came_from()
    {
        $broughtIn = Project::factory()->create(['source_path' => '/srv/acme']);
        $startedHere = Project::factory()->create(['started_here' => true]);

        $this->actingAs($broughtIn->owner)
            ->get(route('projects.show', $broughtIn))
            ->assertInertia(fn (Assert $page) => $page->where('project.source_path', '/srv/acme'));

        // An app started here came from our own template, whose place on
        // our servers is not the owner's business.
        $this->actingAs($startedHere->owner)
            ->get(route('projects.show', $startedHere))
            ->assertInertia(fn (Assert $page) => $page->where('project.source_path', null));
    }

    public function test_the_apps_list_says_what_waits_and_when_it_went_live()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        FeatureRequest::factory()->for($project)->count(2)->create(['status' => FeatureRequestStatus::Generated]);
        FeatureRequest::factory()->for($project)->create(['status' => FeatureRequestStatus::Generated, 'commit_sha' => 'a', 'accepted_at' => now()]);
        Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Published, 'finished_at' => now()]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.waiting', 2)
                ->whereType('projects.0.published_at', 'string')
                ->whereType('projects.0.edited_at', 'string')
                ->missing('projects.0.source_path'));
    }

    public function test_an_app_that_never_went_live_says_so()
    {
        $user = User::factory()->create();
        Project::factory()->for($user, 'owner')->create();

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.0.waiting', 0)
                ->where('projects.0.published_at', null)
                ->whereType('projects.0.edited_at', 'string'));
    }
}
