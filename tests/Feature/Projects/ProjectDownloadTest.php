<?php

namespace Tests\Feature\Projects;

use App\Context\ProjectNotes;
use App\Models\Experiment;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;
use ZipArchive;

class ProjectDownloadTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_the_owner_downloads_the_app_code_in_one_folder_without_its_history_or_notes()
    {
        $project = Project::factory()->create(['name' => 'Clean Homes', 'source_path' => $this->makeProjectSource($this->laravelApp())]);
        app(ProjectRepository::class)->import($project);
        app(ProjectNotes::class)->put($project, Experiment::mainBranch(), ['project.md' => "# Clean Homes\n\nCleaners see their jobs.\n"]);

        $response = $this->actingAs($project->owner)->get(route('projects.download', $project));

        $response->assertOk()->assertDownload('clean-homes.zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $this->assertNotFalse($zip->locateName('clean-homes/app/Models/Team.php'));
        $this->assertFalse($zip->locateName('clean-homes/'.ProjectNotes::directory().'/project.md'));
        $this->assertFalse($zip->locateName('clean-homes/.git/HEAD'));
        $zip->close();
    }

    public function test_an_app_with_no_code_yet_has_nothing_to_download()
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)->get(route('projects.download', $project))->assertNotFound();
    }

    public function test_only_the_owner_downloads_the_app()
    {
        $project = Project::factory()->create();

        $this->actingAs(Project::factory()->create()->owner)->get(route('projects.download', $project))->assertForbidden();
    }
}
