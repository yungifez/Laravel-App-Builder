<?php

namespace Tests\Feature\Changes;

use App\Actions\Projects\CreateProject;
use App\Enums\PreviewStatus;
use App\Enums\RunStatus;
use App\Jobs\StartPreview;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * Keeping a change leaves the owner looking at their app: the pane they
 * tried it in never closes to an empty chat.
 */
class KeepOpensAppTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const ADD_COMMENT = "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n";

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(['app/A.php' => "<?php\n"]), draftNotes: false);
    }

    public function test_keeping_a_change_while_the_app_is_not_open_starts_it(): void
    {
        Queue::fake([StartPreview::class]);
        $change = $this->completedChange();

        $this->keep($change)->assertSessionHasNoErrors();

        $this->assertNotNull($change->refresh()->commit_sha);
        Queue::assertPushed(StartPreview::class, fn (StartPreview $job) => $job->preview->feature_request_id === null
            && $job->preview->revision === $this->repository->head($this->project));
        $this->assertPreviewShown(PreviewStatus::Starting);
    }

    public function test_keeping_a_change_while_the_app_is_open_does_not_start_another(): void
    {
        Queue::fake([StartPreview::class]);
        $this->fakeWorkspaces();
        $open = Preview::factory()->editable($this->repository->head($this->project))->ready()->create(['project_id' => $this->project->id]);

        $this->keep($this->completedChange())->assertSessionHasNoErrors();

        Queue::assertNotPushed(StartPreview::class);
        $this->assertSame([$open->id], $this->project->previews()->pluck('id')->all());
    }

    public function test_an_app_that_could_not_start_after_a_keep_says_why_and_the_change_stays_kept(): void
    {
        $this->fakeWorkspaces()->failCreate = true;
        config(['builder.preview.workspace_driver' => 'fake']);
        $change = $this->completedChange();

        $this->keep($change)->assertSessionHasNoErrors();

        $this->assertNotNull($change->refresh()->commit_sha);
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.status', PreviewStatus::Failed->value)
                ->whereNot('preview.error', null)
                ->etc());
    }

    protected function keep(FeatureRequest $change): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('feature-requests.acceptance.store', $change));
    }

    protected function completedChange(): FeatureRequest
    {
        $change = FeatureRequest::factory()->generated()->for($this->project)->create([
            'patch' => self::ADD_COMMENT,
            'base_revision' => $this->repository->head($this->project),
        ]);
        Run::factory()->for($change)->create(['status' => RunStatus::Completed]);

        return $change;
    }

    protected function assertPreviewShown(PreviewStatus $status): void
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('preview.status', $status->value)->etc());
    }
}
