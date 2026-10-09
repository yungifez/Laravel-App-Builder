<?php

namespace Tests\Feature\Changes;

use App\Actions\Features\RetryFeatureRequest;
use App\Actions\Projects\CreateProject;
use App\Enums\ChecksStoppedBecause;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\VerificationStatus;
use App\Jobs\StartPreview;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/*
| When only our review doubted a change, and the app's own checks did not
| fail, the owner may keep it anyway as their own decision. A change whose
| checks failed, or that can only be made again, is never kept this way.
*/
class KeepDespiteReviewTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const ADD_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n";

    protected const REFUSED = 'Only a change whose run completed can be accepted.';

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([StartPreview::class]);
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(['app/A.php' => "<?php\n"]), draftNotes: false);
    }

    public function test_the_owner_keeps_a_change_only_our_review_doubted()
    {
        $change = $this->doubtedChange(VerificationStatus::Passed);

        $this->keep($change)->assertSessionHasNoErrors();

        $run = $change->latestRun->refresh();
        $this->assertSame($this->repository->head($this->project), $change->refresh()->commit_sha);
        // The owner stood in for the review: the change is done, and
        // nothing offers to try it again.
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertNull($run->stop_reason);
        $this->assertFalse(RetryFeatureRequest::retryable($change->refresh()));
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame(['app/A.php: The comment says nothing.'], $run->events()->where('type', 'change_accepted')->sole()->data['despite_review']);
    }

    public function test_a_change_whose_checks_could_not_run_may_be_kept_too_but_only_when_asked()
    {
        $change = $this->doubtedChange(VerificationStatus::Unverified);
        $head = $this->repository->head($this->project);

        // Keeping as usual is not enough: the owner decides on the doubt.
        $this->keep($change, anyway: false)->assertSessionHasErrors(['change' => self::REFUSED]);
        $this->assertSame($head, $this->repository->head($this->project));

        $this->keep($change)->assertSessionHasNoErrors();
        $this->assertNotNull($change->refresh()->commit_sha);
    }

    public function test_a_change_whose_checks_failed_or_that_must_be_made_again_is_never_kept_anyway()
    {
        $head = $this->repository->head($this->project);

        $failed = $this->doubtedChange(VerificationStatus::Failed);
        $this->keep($failed)->assertSessionHasErrors(['change' => self::REFUSED]);

        $stoppedByChecks = $this->doubtedChange(VerificationStatus::Passed, StopReason::VerificationFailed);
        $this->keep($stoppedByChecks)->assertSessionHasErrors(['change' => self::REFUSED]);

        $uninstallable = $this->doubtedChange(VerificationStatus::Passed);
        Verification::factory()->for($uninstallable)->create(['status' => VerificationStatus::Errored, 'stopped_because' => ChecksStoppedBecause::ChangeInstall, 'error' => ChecksStoppedBecause::ChangeInstall->message()]);
        $this->keep($uninstallable)->assertSessionHasErrors('change');

        $this->assertSame($head, $this->repository->head($this->project));
        $this->assertSame(RunStatus::NeedsUserDecision, $failed->latestRun->refresh()->status);
    }

    protected function keep(FeatureRequest $change, bool $anyway = true): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $change), $anyway ? ['despite_review' => '1'] : []);
    }

    protected function doubtedChange(VerificationStatus $checks, StopReason $stop = StopReason::ReviewFindings): FeatureRequest
    {
        $change = FeatureRequest::factory()->generated()->for($this->project)->create([
            'patch' => self::ADD_COMMENT,
            'base_revision' => $this->repository->head($this->project),
        ]);
        Run::factory()->for($change)->create([
            'status' => RunStatus::NeedsUserDecision,
            'stop_reason' => $stop,
            'feedback' => ['reason' => 'review_findings', 'details' => ['app/A.php: The comment says nothing.']],
        ]);
        Verification::factory()->for($change)->create(['status' => $checks]);

        return $change;
    }
}
