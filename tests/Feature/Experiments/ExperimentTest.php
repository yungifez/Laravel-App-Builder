<?php

namespace Tests\Feature\Experiments;

use App\Actions\Projects\CreateProject;
use App\Context\ProjectNotes;
use App\Enums\ExperimentStatus;
use App\Enums\RunStatus;
use App\Models\Experiment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ExperimentTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    protected const ADD_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n";

    protected const ADD_OTHER_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// other\n";

    protected const ADD_FILE = "diff --git a/app/B.php b/app/B.php\nnew file mode 100644\n--- /dev/null\n+++ b/app/B.php\n@@ -0,0 +1 @@\n+<?php\n";

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(['app/A.php' => "<?php\n"]), draftNotes: false);
    }

    public function test_the_owner_starts_an_idea_on_its_own_branch_and_works_in_it()
    {
        $main = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->post(route('experiments.store', $this->project), ['name' => 'A bigger booking form'])
            ->assertRedirect(route('projects.show', $this->project));

        $experiment = $this->project->experiments()->sole();
        $this->assertSame("ideas/{$experiment->id}", $experiment->branch);
        $this->assertSame($main, $experiment->base_sha);
        $this->assertSame(ExperimentStatus::Open, $experiment->status);
        $this->assertSame($experiment->id, $this->project->refresh()->experiment_id);
        $this->assertSame($main, $this->repository->head($this->project, $experiment->branch));

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('ideas.current.name', 'A bigger booking form')
                ->where('ideas.open.0.id', $experiment->id)
                ->where('ideas.main', 'main'));
    }

    public function test_changes_kept_in_an_idea_stay_off_the_main_app()
    {
        $main = $this->repository->head($this->project);
        $mainChange = $this->completedChange(self::ADD_FILE);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $mainChange))->assertSessionHasNoErrors();
        $main = $this->repository->head($this->project);

        $this->actingAs($this->owner)->post(route('experiments.store', $this->project), ['name' => 'Comments']);
        $experiment = $this->project->experiments()->sole();

        $this->actingAs($this->owner)->post(route('feature-requests.store', $this->project), ['prompt' => 'Add a comment']);
        $asked = $this->project->featureRequests()->latest('id')->first();
        $this->assertSame($experiment->id, $asked->experiment_id);
        $this->assertSame($main, $asked->base_revision);

        $change = $this->completedChange(self::ADD_COMMENT, ['experiment_id' => $experiment->id]);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $change))->assertSessionHasNoErrors();

        $this->assertSame($main, $this->repository->head($this->project, 'main'));
        $this->assertSame($change->refresh()->commit_sha, $this->repository->head($this->project, $experiment->branch));
        $this->assertSame("<?php\n", $this->repository->show($this->project, $main, 'app/A.php'));

        // The idea's chat holds only its own changes; the main app's holds its own.
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('changes', 2)
                ->where('history.0.sha', $change->commit_sha));

        $this->actingAs($this->owner)->put(route('projects.experiment.update', $this->project), ['experiment' => null])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('ideas.current', null)
                ->has('changes', 1)
                ->where('changes.0.id', $mainChange->id)
                ->where('history.0.sha', $main));
    }

    public function test_using_an_idea_merges_it_into_the_main_app()
    {
        $this->actingAs($this->owner)->post(route('experiments.store', $this->project), ['name' => 'Comments']);
        $experiment = $this->project->experiments()->sole();
        $change = $this->completedChange(self::ADD_COMMENT, ['experiment_id' => $experiment->id]);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $change));

        $this->actingAs($this->owner)
            ->post(route('experiments.merge.store', $experiment))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $this->project));

        $experiment->refresh();
        $main = $this->repository->head($this->project, 'main');
        $this->assertSame(ExperimentStatus::Merged, $experiment->status);
        $this->assertSame($main, $experiment->merge_sha);
        $this->assertSame("<?php\n// added\n", $this->repository->show($this->project, $main, 'app/A.php'));
        $this->assertSame("Merge branch '{$experiment->branch}'", $this->repository->log($this->project, 1, 'main')[0]['subject']);
        $this->assertSame('', trim($this->repository->git($this->project, ['branch', '--list', $experiment->branch])->output()));
        $this->assertNull($this->project->refresh()->experiment_id);

        // Its change now belongs to the main app, and can be undone there.
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('changes.0.id', $change->id)->where('changes.0.state', 'kept'));
        $this->actingAs($this->owner)->post(route('feature-requests.reversion.store', $change))->assertSessionHasNoErrors();
        $this->assertSame("<?php\n", $this->repository->show($this->project, $this->repository->head($this->project, 'main'), 'app/A.php'));
    }

    public function test_an_idea_that_clashes_with_the_main_app_is_not_merged()
    {
        $this->actingAs($this->owner)->post(route('experiments.store', $this->project), ['name' => 'Comments']);
        $experiment = $this->project->experiments()->sole();
        $ideaChange = $this->completedChange(self::ADD_COMMENT, ['experiment_id' => $experiment->id]);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $ideaChange));

        $this->actingAs($this->owner)->put(route('projects.experiment.update', $this->project), ['experiment' => null]);
        $mainChange = $this->completedChange(self::ADD_OTHER_COMMENT);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $mainChange))->assertSessionHasNoErrors();
        $main = $this->repository->head($this->project, 'main');

        $this->actingAs($this->owner)
            ->post(route('experiments.merge.store', $experiment))
            ->assertSessionHasErrors(['experiment' => 'Your app changed in the same places since you started this idea. Ask for the change again in your app instead.']);

        $this->assertSame($main, $this->repository->head($this->project, 'main'));
        $this->assertSame(ExperimentStatus::Open, $experiment->refresh()->status);
        $this->assertSame($ideaChange->refresh()->commit_sha, $this->repository->head($this->project, $experiment->branch));
        $this->assertSame('', trim($this->repository->git($this->project, ['status', '--porcelain'])->output()));
    }

    public function test_throwing_an_idea_away_deletes_it_and_stops_its_work()
    {
        $main = $this->repository->head($this->project);
        $this->actingAs($this->owner)->post(route('experiments.store', $this->project), ['name' => 'Comments']);
        $experiment = $this->project->experiments()->sole();
        $waiting = $this->completedChange(self::ADD_COMMENT, ['experiment_id' => $experiment->id]);
        $building = FeatureRequest::factory()->for($this->project)->create(['experiment_id' => $experiment->id]);
        $run = Run::factory()->implementing()->for($building)->create();

        $this->actingAs($this->owner)
            ->delete(route('experiments.destroy', $experiment))
            ->assertRedirect(route('projects.show', $this->project));

        $this->assertSame(ExperimentStatus::Discarded, $experiment->refresh()->status);
        $this->assertNull($this->project->refresh()->experiment_id);
        $this->assertSame($main, $this->repository->head($this->project, 'main'));
        $this->assertSame('', trim($this->repository->git($this->project, ['branch', '--list', $experiment->branch])->output()));
        $this->assertContains($run->refresh()->status, [RunStatus::Cancelling, RunStatus::Cancelled]);

        $this->actingAs($this->owner)
            ->post(route('feature-requests.acceptance.store', $waiting))
            ->assertSessionHasErrors(['change' => 'This idea was thrown away, so its changes cannot be kept.']);
    }

    public function test_an_idea_has_its_own_notes_until_it_is_used_or_thrown_away()
    {
        $notes = app(ProjectNotes::class);
        $notes->put($this->project, 'main', ['project.md' => "A shop.\n", 'capabilities/plans.md' => "Plans.\n"]);

        $this->actingAs($this->owner)->post(route('experiments.store', $this->project), ['name' => 'Coupons']);
        $idea = $this->project->experiments()->sole();
        $this->assertSame($notes->files($this->project, 'main'), $notes->files($this->project, $idea->branch));

        $notes->put($this->project, $idea->branch, ['capabilities/coupons.md' => "Coupons.\n"]);
        $notes->put($this->project, 'main', ['capabilities/plans.md' => "Plans, changed in the app.\n"]);
        $this->assertArrayNotHasKey('capabilities/coupons.md', $notes->files($this->project, 'main'));

        $this->actingAs($this->owner)->post(route('experiments.merge.store', $idea))->assertSessionHasNoErrors();

        $this->assertSame([
            'capabilities/coupons.md' => "Coupons.\n",
            'capabilities/plans.md' => "Plans, changed in the app.\n",
            'project.md' => "A shop.\n",
        ], $notes->files($this->project, 'main'));
        $this->assertSame([], $notes->files($this->project, $idea->branch));

        $this->actingAs($this->owner)->post(route('experiments.store', $this->project), ['name' => 'Gift cards']);
        $thrownAway = $this->project->experiments()->latest('id')->first();
        $notes->put($this->project, $thrownAway->branch, ['project.md' => "A gift card shop.\n"]);
        $this->actingAs($this->owner)->delete(route('experiments.destroy', $thrownAway));

        $this->assertSame([], $notes->files($this->project, $thrownAway->branch));
        $this->assertSame("A shop.\n", $notes->files($this->project, 'main')['project.md']);
    }

    public function test_only_the_main_app_is_published()
    {
        $this->project->update(['deploy_remote' => 'https://example.com/app.git', 'deploy_branch' => 'main']);
        $main = $this->repository->head($this->project);
        $this->actingAs($this->owner)->post(route('experiments.store', $this->project), ['name' => 'Comments']);
        $experiment = $this->project->experiments()->sole();
        $change = $this->completedChange(self::ADD_COMMENT, ['experiment_id' => $experiment->id]);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $change));

        $this->actingAs($this->owner)->post(route('deployments.store', $this->project))->assertSessionHasNoErrors();

        $this->assertSame($main, $this->project->deployments()->sole()->commit_sha);
    }

    public function test_ideas_can_only_be_used_and_moved_between_by_the_owner_and_only_while_open()
    {
        $this->actingAs($this->owner)->post(route('experiments.store', $this->project), ['name' => 'Comments']);
        $experiment = $this->project->experiments()->sole();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('experiments.store', $this->project), ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($stranger)->post(route('experiments.merge.store', $experiment))->assertForbidden();
        $this->actingAs($stranger)->delete(route('experiments.destroy', $experiment))->assertForbidden();
        $this->actingAs($stranger)->put(route('projects.experiment.update', $this->project), ['experiment' => null])->assertForbidden();

        $other = Experiment::factory()->create();
        $this->actingAs($this->owner)->put(route('projects.experiment.update', $this->project), ['experiment' => $other->id])->assertNotFound();

        $this->actingAs($this->owner)->delete(route('experiments.destroy', $experiment));
        $this->actingAs($this->owner)
            ->put(route('projects.experiment.update', $this->project), ['experiment' => $experiment->id])
            ->assertSessionHasErrors(['experiment' => 'This idea is not open any more.']);
        $this->actingAs($this->owner)
            ->post(route('experiments.merge.store', $experiment))
            ->assertSessionHasErrors(['experiment' => 'This idea is not open any more.']);
    }

    protected function completedChange(string $patch, array $attributes = []): FeatureRequest
    {
        $experiment = isset($attributes['experiment_id']) ? Experiment::find($attributes['experiment_id']) : null;

        $request = FeatureRequest::factory()->generated()->for($this->project)->create([
            'patch' => $patch,
            'base_revision' => $this->repository->head($this->project, Experiment::branchOf($experiment)),
            ...$attributes,
        ]);

        Run::factory()->for($request)->create(['status' => RunStatus::Completed]);

        return $request;
    }
}
