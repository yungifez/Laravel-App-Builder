<?php

namespace Tests\Feature\Workspaces;

use App\Actions\Runs\AcquireRunLease;
use App\Actions\Runs\ExtractCandidateChange;
use App\Actions\Runs\PrepareRunWorkspace;
use App\Context\ProjectNotes;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\WorkspaceFile;
use App\Workspaces\WorkspaceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class WorkspaceFilesTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_a_workspace_gets_the_saved_notes_and_files_from_the_database()
    {
        $this->buildInLocalWorkspaces();
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        app(ProjectNotes::class)->put($project, 'main', ['project.md' => "A shop.\n", 'capabilities/plans.md' => "Plans.\n"]);
        WorkspaceFile::factory()->for($project)->create(['path' => '.env', 'contents' => "APP_NAME=Shop\n"]);

        $run = $this->prepare(FeatureRequest::factory()->for($project)->create());

        $this->assertSame("A shop.\n", File::get($this->workspaceFile($run, '.product-notes/project.md')));
        $this->assertSame("Plans.\n", File::get($this->workspaceFile($run, '.product-notes/capabilities/plans.md')));
        $this->assertSame("APP_NAME=Shop\n", File::get($this->workspaceFile($run, '.env')));
    }

    public function test_a_follow_up_starts_from_the_notes_its_parent_left()
    {
        $this->buildInLocalWorkspaces();
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        app(ProjectNotes::class)->put($project, 'main', ['project.md' => "A shop.\n"]);
        $parent = FeatureRequest::factory()->for($project)->create([
            'patch' => "diff --git a/app/Plan.php b/app/Plan.php\nnew file mode 100644\n--- /dev/null\n+++ b/app/Plan.php\n@@ -0,0 +1 @@\n+<?php\n",
            'note_changes' => ['project.md' => ['before' => "A shop.\n", 'after' => "A shop with plans.\n"]],
        ]);

        $run = $this->prepare(FeatureRequest::factory()->for($project)->create(['parent_id' => $parent->id, 'target_step' => 'permission']));

        $this->assertSame("A shop with plans.\n", File::get($this->workspaceFile($run, '.product-notes/project.md')));
    }

    public function test_the_first_workspace_saves_its_env_file_encrypted()
    {
        $this->buildInLocalWorkspaces();
        [$run] = $this->implementingRun();
        File::put($this->workspaceFile($run, '.env'), "APP_KEY=secret\n");

        app(WorkspaceFiles::class)->sync($run->featureRequest->project, $run->workspace);

        $this->assertSame("APP_KEY=secret\n", $run->featureRequest->project->workspaceFiles()->sole()->contents);
        $this->assertStringNotContainsString('secret', (string) DB::table('workspace_files')->value('contents'));
    }

    public function test_notes_a_run_changed_are_kept_apart_from_its_code_change()
    {
        $this->buildInLocalWorkspaces();
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        app(ProjectNotes::class)->put($project, 'main', ['project.md' => "A shop.\n", 'old.md' => "Gone soon.\n"]);
        $run = $this->prepare(FeatureRequest::factory()->for($project)->create());

        File::put($this->workspaceFile($run, 'app/Models/Team.php'), "<?php\n");
        File::put($this->workspaceFile($run, '.product-notes/project.md'), "A shop with plans.\n");
        File::ensureDirectoryExists($this->workspaceFile($run, '.product-notes/capabilities'));
        File::put($this->workspaceFile($run, '.product-notes/capabilities/plans.md'), "Plans.\n");
        File::delete($this->workspaceFile($run, '.product-notes/old.md'));

        $extract = app(ExtractCandidateChange::class);
        $patch = $extract->handle($run->workspace);

        $this->assertStringContainsString('app/Models/Team.php', $patch);
        $this->assertStringNotContainsString('.product-notes', $patch);
        $this->assertEquals([
            'capabilities/plans.md' => ['before' => null, 'after' => "Plans.\n"],
            'old.md' => ['before' => "Gone soon.\n", 'after' => null],
            'project.md' => ['before' => "A shop.\n", 'after' => "A shop with plans.\n"],
        ], $extract->notes($run->workspace));
    }

    /**
     * Prepare a workspace for a run of the change.
     */
    protected function prepare(FeatureRequest $featureRequest): Run
    {
        $run = Run::factory()->implementing()->for($featureRequest)->create();
        $lease = app(AcquireRunLease::class)->handle($run, 'worker-a');
        $this->assertNotNull($lease);

        app(PrepareRunWorkspace::class)->handle($run, $lease);

        return $run->refresh();
    }
}
