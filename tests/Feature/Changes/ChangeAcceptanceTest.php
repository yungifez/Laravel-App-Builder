<?php

namespace Tests\Feature\Changes;

use App\Actions\Projects\CreateProject;
use App\Actions\Runs\AcquireRunLease;
use App\Actions\Runs\PrepareRunWorkspace;
use App\Context\ProjectNotes;
use App\Enums\RunStatus;
use App\Features\AppDrift;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ChangeAcceptanceTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    protected const ADD_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n";

    protected const ADD_TEAMS_COMMENT = "diff --git a/config/teams.php b/config/teams.php\n--- a/config/teams.php\n+++ b/config/teams.php\n@@ -1,3 +1,4 @@\n <?php\n \n+// teams\n return [\n";

    protected const ADD_SHORTCUTS = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,3 @@\n <?php\n+\$teams = Team::all();\n+try { go(); } catch (Exception \$e) {}\n";

    protected const REMOVE_ALL_TEAMS = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1,3 +1,3 @@\n <?php\n-\$teams = Team::all();\n+\$teams = Team::query()->take(10)->get();\n try { go(); } catch (Exception \$e) {}\n";

    protected const ADD_SECOND_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1,2 +1,3 @@\n <?php\n // added\n+// second\n";

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(['app/A.php' => "<?php\n"]), draftNotes: false);
    }

    public function test_the_owner_accepts_a_completed_change_as_a_commit_they_author()
    {
        $request = $this->completedChange(self::ADD_COMMENT);

        $this->actingAs($this->owner)
            ->from(route('projects.show', ['project' => $request->project, 'change' => $request->uuid]))
            ->post(route('feature-requests.acceptance.store', $request))
            ->assertRedirect(route('projects.show', ['project' => $request->project, 'change' => $request->uuid]));

        $request->refresh();
        $this->assertNotNull($request->accepted_at);
        $this->assertSame($this->repository->head($this->project), $request->commit_sha);
        $this->assertSame("<?php\n// added\n", File::get($this->repository->path($this->project).'/app/A.php'));

        $commit = $this->repository->log($this->project)[0];
        $this->assertSame('Update the application', $commit['subject']);
        $this->assertSame('Ada Owner', $commit['author']);
        $this->assertSame('Update the application', trim($this->repository->git($this->project, ['log', '-1', '--format=%B'])->output()));
        $this->assertSame('Ada Owner', trim($this->repository->git($this->project, ['log', '-1', '--format=%cn'])->output()));
        $this->assertSame('change_accepted', $request->latestRun->events()->latest('sequence')->first()->type);
    }

    public function test_the_commit_reads_like_a_developer_wrote_it()
    {
        $request = $this->completedChange(self::ADD_COMMENT);
        $request->latestRun->update(['plan' => ['commit_subject' => 'Add comments to posts']]);

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $request))->assertSessionHasNoErrors();

        $message = $this->repository->git($this->project, ['log', '-1', '--format=%B%n%an%n%cn%n%ce'])->output();
        $this->assertStringStartsWith("Add comments to posts\n", $message);
        $this->assertStringNotContainsString($request->prompt, $message);
        $this->assertStringNotContainsStringIgnoringCase('builder', $message);
    }

    public function test_the_next_request_builds_on_the_accepted_commit()
    {
        // Only where the next request starts matters, not building it.
        Queue::fake([ExecuteRun::class]);
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

    public function test_once_a_change_is_kept_each_shortcut_it_added_is_weighed_with_the_whole_file()
    {
        // The weighing is what matters, not building the tidy-up it asks for.
        Queue::fake([ExecuteRun::class]);
        config(['ai.providers.typesafe.key' => 'test-key', 'builder.decisions.providers' => ['typesafe']]);
        Classification::fake([['real' => new BooleanAnswer(0.92)], ['real' => new BooleanAnswer(0.1)]]);
        $request = $this->completedChange(self::ADD_SHORTCUTS);
        Verification::factory()->for($request)->create(['shortcuts' => [
            ['rule' => 'SL210', 'path' => 'app/A.php', 'line' => 2],
            ['rule' => 'SL107', 'path' => 'app/A.php', 'line' => 3],
        ]]);

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $request))->assertSessionHasNoErrors();

        Classification::assertClassified(fn (ClassificationPrompt $prompt) => $prompt->state['file'] === "<?php\n\$teams = Team::all();\ntry { go(); } catch (Exception \$e) {}\n" && $prompt->state['line'] === 2 && str_contains($prompt->state['concern'], 'every row'));
        $triaged = $request->latestRun->events()->where('type', 'shortcuts_triaged')->sole()->data;
        $this->assertSame($request->refresh()->commit_sha, $triaged['commit']);
        $this->assertSame(['real', 'not_real'], array_column($triaged['shortcuts'], 'verdict'));
        $this->assertSame(2, $request->latestRun->events()->where('type', 'model_call')->where('data->role', 'triage')->count());
    }

    public function test_keeping_a_change_moves_each_areas_ceiling_down_and_up_only_where_the_owner_wants_it()
    {
        $request = $this->completedChange(self::ADD_COMMENT);
        $this->project->forceFill(['drift_ceilings' => ['billing' => 5.0, 'blog' => 3.0, 'orders' => 2.0]])->save();
        Verification::factory()->for($request)->create(['evidence' => ['drift' => ['areas' => [
            'billing' => ['requests' => 4, 'effects' => 8, 'per' => 2.0],
            'blog' => ['requests' => 4, 'effects' => 40, 'per' => 10.0],
            'orders' => ['requests' => 4, 'effects' => 16, 'per' => 4.0],
        ], 'findings' => []]]]);
        $request->acceptedFindings()->create(['kind' => AppDrift::GREW, 'identity' => AppDrift::GREW.'|blog', 'user_id' => $this->owner->id]);

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $request))->assertSessionHasNoErrors();

        // Orders grew without the owner's word, so later changes are held to the old ceiling.
        $this->assertSame(['billing' => 2.2, 'blog' => 11, 'orders' => 2], $this->project->refresh()->drift_ceilings);
    }

    public function test_a_shortcut_a_follow_up_took_out_is_not_weighed()
    {
        config(['ai.providers.typesafe.key' => 'test-key', 'builder.decisions.providers' => ['typesafe']]);
        Classification::fake()->preventStrayClassifications();
        $parent = $this->completedChange(self::ADD_SHORTCUTS);
        Verification::factory()->for($parent)->create(['shortcuts' => [['rule' => 'SL210', 'path' => 'app/A.php', 'line' => 2]]]);
        $followUp = $this->completedChange(self::REMOVE_ALL_TEAMS, ['parent_id' => $parent->id, 'target_step' => 'permission']);

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $followUp))->assertSessionHasNoErrors();

        Classification::assertNothingClassified();
        $triaged = $parent->latestRun->events()->where('type', 'shortcuts_triaged')->sole()->data;
        $this->assertSame([['rule' => 'SL210', 'path' => 'app/A.php', 'line' => 2, 'verdict' => 'gone']], $triaged['shortcuts']);
    }

    public function test_shortcuts_are_not_weighed_without_a_decision_model()
    {
        config(['builder.decisions.providers' => []]);
        Classification::fake()->preventStrayClassifications();
        $request = $this->completedChange(self::ADD_SHORTCUTS);
        Verification::factory()->for($request)->create(['shortcuts' => [['rule' => 'SL210', 'path' => 'app/A.php', 'line' => 2]]]);

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $request))->assertSessionHasNoErrors();

        Classification::assertNothingClassified();
        $this->assertFalse($request->latestRun->events()->where('type', 'shortcuts_triaged')->exists());
    }

    public function test_a_change_checked_on_an_older_app_is_built_again_instead_of_merged()
    {
        Queue::fake([ExecuteRun::class]);
        $first = $this->completedChange(self::ADD_COMMENT);
        $second = $this->completedChange(self::ADD_TEAMS_COMMENT, ['prompt' => 'Note the teams config.']);

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $first))->assertSessionHasNoErrors();
        $head = $this->repository->head($this->project);

        $response = $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $second))->assertSessionHasNoErrors();

        // The two were never checked together, so the second is not merged.
        $rebuild = $this->project->featureRequests()->latest('id')->firstOrFail();
        $response->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $rebuild->uuid]))
            ->assertInertiaFlash('toast.message', 'Your app changed after I checked this, so I am making it again on your app as it is now. You can keep it when it is ready.');
        $this->assertSame($second->id, $rebuild->retry_of_id);
        $this->assertSame('Note the teams config.', $rebuild->prompt);
        $this->assertNull($second->refresh()->commit_sha);
        $this->assertSame($head, $this->repository->head($this->project));
        $this->assertStringNotContainsString('// teams', File::get($this->repository->path($this->project).'/config/teams.php'));
        Queue::assertPushed(ExecuteRun::class);
    }

    public function test_a_follow_up_checked_on_an_older_app_is_asked_again_with_what_it_builds_on()
    {
        Queue::fake([ExecuteRun::class]);
        $other = $this->completedChange(self::ADD_TEAMS_COMMENT);
        $parent = $this->completedChange(self::ADD_COMMENT, ['prompt' => 'Make the first version.']);
        $followUp = $this->completedChange(self::ADD_SECOND_COMMENT, ['parent_id' => $parent->id, 'target_step' => 'permission', 'prompt' => 'Give it its own front page.']);

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $other));
        $head = $this->repository->head($this->project);

        $response = $this->actingAs($this->owner)
            ->post(route('feature-requests.acceptance.store', $followUp))
            ->assertSessionHasNoErrors();

        // The two asks waiting to be kept are built again together on the
        // app as it is now, in the order they were asked.
        $rebuild = $this->project->featureRequests()->latest('id')->firstOrFail();
        $response->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $rebuild->uuid]));
        $this->assertSame($followUp->id, $rebuild->retry_of_id);
        $this->assertNull($rebuild->parent_id);
        $this->assertSame("Make the first version.\n\nGive it its own front page.", $rebuild->prompt);
        $this->assertNull($followUp->refresh()->commit_sha);
        $this->assertNull($parent->refresh()->commit_sha);
        $this->assertSame($head, $this->repository->head($this->project));
        $this->assertSame('', trim($this->repository->git($this->project, ['status', '--porcelain'])->output()));
        Queue::assertPushed(ExecuteRun::class);
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
            ->from(route('feature-requests.show', $request))
            ->post(route('feature-requests.reversion.store', $request))
            ->assertRedirect(route('feature-requests.show', $request));

        $request->refresh();
        $this->assertNotNull($request->reverted_at);
        $this->assertSame($this->repository->head($this->project), $request->revert_sha);
        $this->assertFalse($request->isAccepted());
        $this->assertSame("<?php\n", File::get($this->repository->path($this->project).'/app/A.php'));
        $this->assertSame('Revert "Update the application"', $this->repository->log($this->project)[0]['subject']);

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

    public function test_accepting_a_change_saves_what_it_did_to_the_notes_outside_the_repository()
    {
        $notes = app(ProjectNotes::class);
        $notes->put($this->project, 'main', ['project.md' => "Old.\n", 'capabilities/plans.md' => "Mine.\n"]);
        $request = $this->completedChange(self::ADD_COMMENT, ['note_changes' => [
            'project.md' => ['before' => "Old.\n", 'after' => "New.\n"],
            'capabilities/billing.md' => ['before' => null, 'after' => "Billing.\n"],
            'capabilities/plans.md' => ['before' => "Before someone edited it.\n", 'after' => "Theirs.\n"],
        ]]);

        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $request))->assertSessionHasNoErrors();

        $this->assertSame(
            ['capabilities/billing.md' => "Billing.\n", 'capabilities/plans.md' => "Mine.\n", 'project.md' => "New.\n"],
            $notes->files($this->project),
        );
        $this->assertSame([], preg_grep('/^\\.|notes|capabilities/', array_diff($this->repository->files($this->project, (string) $request->refresh()->commit_sha), ['.gitignore'])));

        $this->actingAs($this->owner)->post(route('feature-requests.reversion.store', $request))->assertSessionHasNoErrors();

        $this->assertSame(['capabilities/plans.md' => "Mine.\n", 'project.md' => "Old.\n"], $notes->files($this->project));
    }

    public function test_the_page_offers_the_decision_without_saying_how_the_change_was_built()
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
                ->missing('run.built_by')
                ->missing('run.events')
                ->where('run.log', fn ($log) => ! str_contains(json_encode($log), 'codex') && ! str_contains(json_encode($log), 'overloaded')));

        $this->post(route('feature-requests.acceptance.store', $request));

        $this->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_accept', false)
                ->where('featureRequest.commit_sha', $request->refresh()->commit_sha));

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('history', 2)
                ->where('changes.0.id', $request->uuid)
                ->where('history.0.sha', $request->commit_sha)
                ->where('changes.0.state', 'kept'));
    }

    public function test_the_page_keeps_how_changes_are_made_to_itself()
    {
        $notes = "diff --git a/.builder/capabilities/teams.md b/.builder/capabilities/teams.md\n--- a/.builder/capabilities/teams.md\n+++ b/.builder/capabilities/teams.md\n@@ -1 +1 @@\n-# Teams\n+# Teams and members\n";
        $request = $this->completedChange(self::ADD_COMMENT.$notes, ['error' => 'The planner returned an invalid plan: tasks is required.']);
        $request->latestRun->update(['error' => 'The run finished without changing the project.']);

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.files', fn ($files) => collect($files)->pluck('path')->all() === ['app/A.php'])
                ->where('featureRequest.error', 'Something went wrong on our side while I worked on this.')
                ->where('run.error', 'The run finished without changing the project.'));
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
