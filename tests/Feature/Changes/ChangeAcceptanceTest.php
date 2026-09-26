<?php

namespace Tests\Feature\Changes;

use App\Actions\Projects\CreateProject;
use App\Actions\Runs\AcquireRunLease;
use App\Actions\Runs\PrepareRunWorkspace;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ChangeAcceptanceTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    protected const ADD_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n";

    protected const ADD_SECOND_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1,2 +1,3 @@\n <?php\n // added\n+// second\n";

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(['app/A.php' => "<?php\n"]));
    }

    public function test_the_owner_accepts_a_completed_change_as_a_commit_they_author()
    {
        $request = $this->completedChange(self::ADD_COMMENT);

        $this->actingAs($this->owner)
            ->post(route('feature-requests.acceptance.store', $request))
            ->assertRedirect(route('feature-requests.show', $request));

        $request->refresh();
        $this->assertNotNull($request->accepted_at);
        $this->assertSame($this->repository->head($this->project), $request->commit_sha);
        $this->assertSame("<?php\n// added\n", File::get($this->repository->path($this->project).'/app/A.php'));

        $commit = $this->repository->log($this->project)[0];
        $this->assertSame('Let team owners invite people by email.', $commit['subject']);
        $this->assertSame('Ada Owner', $commit['author']);
        $this->assertStringContainsString("Builder-Request: #{$request->id}", $this->repository->git($this->project, ['log', '-1', '--format=%B'])->output());
        $this->assertSame('change_accepted', $request->latestRun->events()->latest('sequence')->first()->type);
    }

    public function test_the_next_request_builds_on_the_accepted_commit()
    {
        $request = $this->completedChange(self::ADD_COMMENT);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $request));

        $this->actingAs($this->owner)->post(route('feature-requests.store', $this->project), ['prompt' => 'Add billing']);

        $next = $this->project->featureRequests()->latest('id')->first();
        $this->assertSame($request->refresh()->commit_sha, $next->base_revision);
        $this->assertSame([$next->id], array_map(fn (FeatureRequest $request) => $request->id, $next->lineage()));
    }

    public function test_the_next_run_works_on_the_project_as_accepted_not_on_the_original_source()
    {
        $this->buildInLocalWorkspaces();
        $request = $this->completedChange(self::ADD_COMMENT);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $request));

        $next = FeatureRequest::factory()->for($this->project)->create(['base_revision' => $this->repository->head($this->project)]);
        $run = Run::factory()->implementing()->for($next)->create();
        $lease = app(AcquireRunLease::class)->handle($run, 'worker-a');
        app(PrepareRunWorkspace::class)->handle($run, $lease);

        $this->assertSame("<?php\n// added\n", File::get($this->workspaceFile($run->refresh(), 'app/A.php')));
        $this->assertSame("<?php\n", File::get($this->project->source_path.'/app/A.php'));
    }

    public function test_a_follow_up_is_accepted_together_with_the_change_it_builds_on()
    {
        $parent = $this->completedChange(self::ADD_COMMENT);
        $followUp = $this->completedChange(self::ADD_SECOND_COMMENT, ['parent_id' => $parent->id, 'target_step' => 'permission']);

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $followUp))->assertSessionHasNoErrors();

        $this->assertSame(2, count($this->repository->log($this->project)));
        $this->assertSame($followUp->refresh()->commit_sha, $parent->refresh()->commit_sha);
        $this->assertSame("<?php\n// added\n// second\n", File::get($this->repository->path($this->project).'/app/A.php'));
    }

    public function test_a_change_is_merged_onto_later_commits_when_it_still_fits()
    {
        $first = $this->completedChange(self::ADD_COMMENT);
        $second = $this->completedChange("diff --git a/config/teams.php b/config/teams.php\n--- a/config/teams.php\n+++ b/config/teams.php\n@@ -1,3 +1,4 @@\n <?php\n \n+// teams\n return [\n");

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $first))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $second))->assertSessionHasNoErrors();

        $this->assertSame(3, count($this->repository->log($this->project)));
        $this->assertStringContainsString('// teams', File::get($this->repository->path($this->project).'/config/teams.php'));
    }

    public function test_a_change_that_no_longer_fits_is_refused_and_the_repository_is_left_clean()
    {
        $first = $this->completedChange(self::ADD_COMMENT);
        $clash = $this->completedChange("diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// something else\n");

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $first));
        $head = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->post(route('feature-requests.acceptance.store', $clash))
            ->assertSessionHasErrors('change');

        $this->assertNull($clash->refresh()->commit_sha);
        $this->assertSame($head, $this->repository->head($this->project));
        $this->assertSame('', trim($this->repository->git($this->project, ['status', '--porcelain'])->output()));
    }

    public function test_only_a_completed_change_can_be_accepted_and_only_once()
    {
        $unfinished = $this->completedChange(self::ADD_COMMENT, runStatus: RunStatus::Reviewing);

        $this->actingAs($this->owner)
            ->post(route('feature-requests.acceptance.store', $unfinished))
            ->assertSessionHasErrors('change');

        $request = $this->completedChange(self::ADD_COMMENT);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $request));

        $this->actingAs($this->owner)
            ->post(route('feature-requests.acceptance.store', $request))
            ->assertSessionHasErrors('change');
        $this->assertSame(2, count($this->repository->log($this->project)));
    }

    public function test_the_owner_undoes_an_accepted_change_with_a_new_commit()
    {
        $request = $this->completedChange(self::ADD_COMMENT);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $request));

        $this->actingAs($this->owner)
            ->post(route('feature-requests.reversion.store', $request))
            ->assertRedirect(route('feature-requests.show', $request));

        $request->refresh();
        $this->assertNotNull($request->reverted_at);
        $this->assertSame($this->repository->head($this->project), $request->revert_sha);
        $this->assertFalse($request->isAccepted());
        $this->assertSame("<?php\n", File::get($this->repository->path($this->project).'/app/A.php'));
        $this->assertStringStartsWith('Undo: ', $this->repository->log($this->project)[0]['subject']);

        $this->actingAs($this->owner)
            ->post(route('feature-requests.reversion.store', $request))
            ->assertSessionHasErrors('change');
    }

    public function test_a_change_that_later_changes_build_on_cannot_be_undone_alone()
    {
        $parent = $this->completedChange(self::ADD_COMMENT);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $parent));
        $followUp = $this->completedChange(self::ADD_SECOND_COMMENT, ['parent_id' => $parent->id, 'target_step' => 'permission', 'base_revision' => $parent->refresh()->commit_sha]);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $followUp))->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->post(route('feature-requests.reversion.store', $parent))
            ->assertSessionHasErrors('change');

        $this->assertNull($parent->refresh()->reverted_at);
        $this->assertSame('', trim($this->repository->git($this->project, ['status', '--porcelain'])->output()));
    }

    public function test_the_page_offers_the_decision_and_says_when_the_backup_provider_built_the_change()
    {
        $request = $this->completedChange(self::ADD_COMMENT);
        $run = $request->latestRun;
        $run->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'claude', 'provider' => 'anthropic', 'status' => 'provider_unavailable']);
        $run->recordEvent('failover', ['from' => 'claude', 'to' => 'codex', 'reason' => 'overloaded']);
        $run->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'provider' => 'openai', 'model' => null, 'status' => 'completed']);

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_accept', true)
                ->where('featureRequest.commit_sha', null)
                ->where('run.built_by.adapter', 'codex')
                ->where('run.built_by.backup', true)
                ->where('run.built_by.reason', 'overloaded'));

        $this->post(route('feature-requests.acceptance.store', $request));

        $this->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_accept', false)
                ->where('featureRequest.commit_sha', $request->refresh()->commit_sha));

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('history', 2)
                ->where('history.0.sha', $request->commit_sha)
                ->where('featureRequests.0.accepted', true));
    }

    public function test_other_users_cannot_accept_or_undo_changes()
    {
        $request = $this->completedChange(self::ADD_COMMENT);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('feature-requests.acceptance.store', $request))->assertForbidden();
        $this->actingAs($stranger)->post(route('feature-requests.reversion.store', $request))->assertForbidden();
        $this->assertNull($request->refresh()->commit_sha);
    }

    /**
     * Create a generated request with the given patch, based on the
     * project's current commit, whose latest run has the given status.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function completedChange(string $patch, array $attributes = [], RunStatus $runStatus = RunStatus::Completed): FeatureRequest
    {
        $request = FeatureRequest::factory()->generated()->for($this->project)->create([
            'patch' => $patch,
            'base_revision' => $this->repository->head($this->project),
            ...$attributes,
        ]);

        Run::factory()->for($request)->create(['status' => $runStatus]);

        return $request;
    }
}
