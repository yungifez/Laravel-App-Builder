<?php

namespace Tests\Feature\Changes;

use App\Actions\Projects\CreateProject;
use App\Enums\ChecksStoppedBecause;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Jobs\StartPreview;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class KeepMadeAgainTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const ADD_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n";

    protected const ADD_SECOND_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1,2 +1,3 @@\n <?php\n // added\n+// second\n";

    protected const REFUSED = 'This change cannot be kept as it is. Try again to make it afresh.';

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Keeping a change starts the app; KeepOpensAppTest covers that.
        Bus::fake([StartPreview::class]);
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(['app/A.php' => "<?php\n"]), draftNotes: false);
    }

    public function test_a_change_its_app_could_not_install_is_not_kept(): void
    {
        $change = $this->completedChange(self::ADD_COMMENT);
        $this->stopChecks($change, ChecksStoppedBecause::ChangeInstall);
        $head = $this->repository->head($this->project);

        $this->keep($change)->assertSessionHasErrors(['change' => self::REFUSED]);

        $this->assertNull($change->refresh()->commit_sha);
        $this->assertSame($head, $this->repository->head($this->project));
    }

    public function test_a_change_whose_copy_could_not_be_set_up_can_still_be_kept(): void
    {
        $change = $this->completedChange(self::ADD_COMMENT);
        $this->stopChecks($change, ChecksStoppedBecause::Setup);

        $this->keep($change)->assertSessionHasNoErrors();

        $this->assertSame($this->repository->head($this->project), $change->refresh()->commit_sha);
    }

    public function test_a_follow_up_is_not_kept_with_a_change_that_no_longer_fits(): void
    {
        $parent = $this->completedChange(self::ADD_COMMENT);
        Preview::factory()->create(['project_id' => $this->project->id, 'feature_request_id' => $parent->id, 'no_longer_fits' => true]);
        $followUp = $this->completedChange(self::ADD_SECOND_COMMENT, ['parent_id' => $parent->id, 'target_step' => 'permission']);
        $head = $this->repository->head($this->project);

        $this->keep($followUp)->assertSessionHasErrors(['change' => self::REFUSED]);

        $this->assertNull($parent->refresh()->commit_sha);
        $this->assertNull($followUp->refresh()->commit_sha);
        $this->assertSame($head, $this->repository->head($this->project));
    }

    /**
     * @return TestResponse<Response>
     */
    protected function keep(FeatureRequest $change)
    {
        return $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $change));
    }

    protected function stopChecks(FeatureRequest $change, ChecksStoppedBecause $because): void
    {
        Verification::factory()->for($change)->create(['status' => VerificationStatus::Errored, 'stopped_because' => $because, 'error' => $because->message()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function completedChange(string $patch, array $attributes = []): FeatureRequest
    {
        $change = FeatureRequest::factory()->generated()->for($this->project)->create([
            'patch' => $patch,
            'base_revision' => $this->repository->head($this->project),
            ...$attributes,
        ]);
        Run::factory()->for($change)->create(['status' => RunStatus::Completed]);

        return $change;
    }
}
