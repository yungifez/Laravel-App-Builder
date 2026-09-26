<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\CreateProject;
use App\Context\ProjectNotes;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class MoveProjectNotesTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_notes_older_versions_kept_in_the_app_move_into_the_database()
    {
        $repository = app(ProjectRepository::class);
        $project = app(CreateProject::class)->handle(User::factory()->create(), 'Acme', $this->makeProjectSource(), draftNotes: false);
        $repository->commitFiles($project, $repository->head($project), [
            '.builder/project.md' => "A shop.\n",
            '.builder/capabilities/plans.md' => "Plans.\n",
        ], 'Add notes', null);

        $this->artisan('projects:move-notes')->assertSuccessful();

        $this->assertSame(
            ['capabilities/plans.md' => "Plans.\n", 'project.md' => "A shop.\n"],
            app(ProjectNotes::class)->files($project),
        );
        $head = $repository->head($project);
        $this->assertSame([], preg_grep('/^\.builder\//', $repository->files($project, $head)));
        $this->assertSame('Remove the notes folder', $repository->log($project, 1)[0]['subject']);

        $this->artisan('projects:move-notes')->assertSuccessful();
        $this->assertSame($head, $repository->head($project));
    }
}
