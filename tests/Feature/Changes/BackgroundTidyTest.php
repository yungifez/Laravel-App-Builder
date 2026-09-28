<?php

namespace Tests\Feature\Changes;

use App\Actions\Features\RequestFeature;
use App\Actions\Projects\CreateProject;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Events\RunStatusChanged;
use App\Jobs\DecideFeatureRequest;
use App\Jobs\ExecuteRun;
use App\Jobs\TidyShortcuts;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class BackgroundTidyTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    protected const ADD_SHORTCUTS = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,3 @@\n <?php\n+\$teams = Team::all();\n+try { go(); } catch (Exception \$e) {}\n";

    protected const REMOVE_ALL_TEAMS = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1,3 +1,3 @@\n <?php\n-\$teams = Team::all();\n+\$teams = Team::query()->take(10)->get();\n try { go(); } catch (Exception \$e) {}\n";

    protected const LEAVE_ALL_TEAMS = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1,3 +1,4 @@\n <?php\n \$teams = Team::all();\n+\$count = 1;\n try { go(); } catch (Exception \$e) {}\n";

    protected const SHORTCUT = ['rule' => 'SL210', 'path' => 'app/A.php', 'line' => 2];

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected FeatureRequest $kept;

    protected function setUp(): void
    {
        parent::setUp();

        // The decision model is not asked here; the tidy-up is started by hand.
        config(['builder.decisions.providers' => []]);
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(['app/A.php' => "<?php\n"]), draftNotes: false);
        $this->kept = $this->completedChange(self::ADD_SHORTCUTS);
        $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $this->kept))->assertSessionHasNoErrors();
        $this->kept->refresh();
    }

    public function test_a_real_shortcut_becomes_a_tidy_up_the_app_asks_for_itself()
    {
        Queue::fake([ExecuteRun::class, DecideFeatureRequest::class]);

        TidyShortcuts::dispatch($this->kept, [self::SHORTCUT]);

        $tidy = FeatureRequest::query()->whereNotNull('tidy')->sole();
        $this->assertSame("Tidy up one thing in my app's code.", $tidy->prompt);
        $this->assertSame($this->owner->id, $tidy->user_id);
        $this->assertNull($tidy->experiment_id);
        $this->assertSame(['of' => $this->kept->id, 'tier' => 'light', 'shortcuts' => [self::SHORTCUT]], $tidy->tidy);
        $this->assertStringContainsString('- Line 2 of app/A.php loads every row of a table at once', $tidy->instructions());
        Queue::assertPushed(ExecuteRun::class);
    }

    public function test_the_tidy_up_waits_while_the_owner_has_a_change_being_built()
    {
        FeatureRequest::factory()->for($this->project)->create(['status' => FeatureRequestStatus::Generating]);
        $job = (new TidyShortcuts($this->kept, [self::SHORTCUT]))->withFakeQueueInteractions();

        $job->handle(app(RequestFeature::class));

        $job->assertReleased(15 * 60);
        $this->assertFalse(FeatureRequest::query()->whereNotNull('tidy')->exists());
    }

    public function test_a_tidy_up_that_passed_and_fixed_the_shortcut_is_kept_without_bothering_the_owner()
    {
        Notification::fake();
        $tidy = $this->tidy(self::REMOVE_ALL_TEAMS, 'light');

        $this->finish($tidy, RunStatus::Completed);

        $tidy->refresh();
        $this->assertNotNull($tidy->commit_sha);
        $this->assertSame($this->repository->head($this->project), $tidy->commit_sha);
        $this->assertSame('Ada Owner', $this->repository->log($this->project)[0]['author']);
        Notification::assertNothingSent();
    }

    public function test_a_light_tidy_up_that_left_the_shortcut_goes_to_the_usual_model()
    {
        $head = $this->repository->head($this->project);
        $tidy = $this->tidy(self::LEAVE_ALL_TEAMS, 'light');
        Queue::fake([TidyShortcuts::class]);

        $this->finish($tidy, RunStatus::Completed);

        $this->assertNull($tidy->refresh()->commit_sha);
        $this->assertNotNull($tidy->dismissed_at);
        $this->assertSame($head, $this->repository->head($this->project));
        $this->assertSame('shortcuts_left', $tidy->latestRun->events()->where('type', 'tidy_put_aside')->sole()->data['reason']);
        Queue::assertPushed(TidyShortcuts::class, fn (TidyShortcuts $job) => $job->tier === 'full' && $job->featureRequest->is($this->kept) && $job->shortcuts === [self::SHORTCUT]);
    }

    public function test_a_tidy_up_the_usual_model_could_not_finish_is_put_aside_and_the_app_left_as_it_was()
    {
        $head = $this->repository->head($this->project);
        $tidy = $this->tidy(self::REMOVE_ALL_TEAMS, 'full', RunStatus::Failed);
        Queue::fake([TidyShortcuts::class]);

        $this->finish($tidy, RunStatus::Failed);

        $this->assertNotNull($tidy->refresh()->dismissed_at);
        $this->assertSame($head, $this->repository->head($this->project));
        Queue::assertNotPushed(TidyShortcuts::class);
    }

    public function test_a_tidy_up_of_a_change_the_owner_undid_is_put_aside()
    {
        $tidy = $this->tidy(self::REMOVE_ALL_TEAMS, 'light');
        $this->kept->update(['reverted_at' => now()]);
        Queue::fake([TidyShortcuts::class]);

        $this->finish($tidy, RunStatus::Completed);

        $this->assertNull($tidy->refresh()->commit_sha);
        $this->assertSame('undone', $tidy->latestRun->events()->where('type', 'tidy_put_aside')->sole()->data['reason']);
        Queue::assertNotPushed(TidyShortcuts::class);
    }

    /**
     * A built tidy-up of the kept change's shortcut.
     */
    protected function tidy(string $patch, string $tier, RunStatus $runStatus = RunStatus::Completed): FeatureRequest
    {
        return $this->completedChange($patch, ['tidy' => ['of' => $this->kept->id, 'tier' => $tier, 'shortcuts' => [self::SHORTCUT]]], $runStatus);
    }

    /**
     * Say the request's run has finished, as the run does.
     */
    protected function finish(FeatureRequest $request, RunStatus $to): void
    {
        event(new RunStatusChanged($request->latestRun, RunStatus::Reviewing, $to));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function completedChange(string $patch, array $attributes = [], RunStatus $runStatus = RunStatus::Completed): FeatureRequest
    {
        $request = FeatureRequest::factory()->generated()->for($this->project)->create([
            'user_id' => $this->owner->id,
            'patch' => $patch,
            'base_revision' => $this->repository->head($this->project),
            ...$attributes,
        ]);

        Run::factory()->for($request)->create(['status' => $runStatus]);

        return $request;
    }
}
