<?php

namespace Tests\Feature\Previews;

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PreviewFilesTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    protected Project $project;

    protected Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $this->workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $this->workspace->id]);

        $this->driver->onExec = fn () => new CommandResult(exitCode: 0, output: json_encode([
            ['public/avatars/jane.png', 2048, 1790000000],
            ['private/invoices/march.pdf', 51200, 1780000000],
            ['public/logo.svg', 300, 1770000000],
        ]), errorOutput: '', durationMs: 5);
        $this->driver->files["{$this->workspace->driver_id}:storage/app/public/avatars/jane.png"] = 'PNG-BYTES';
        $this->driver->files["{$this->workspace->driver_id}:storage/app/public/logo.svg"] = '<svg onload="alert(1)"/>';
    }

    public function test_the_owner_sees_the_files_their_app_stored_newest_first()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('files')->reloadOnly('files', fn (Assert $page) => $page
                ->count('files', 3)
                ->where('files.0.name', 'jane.png')
                ->where('files.0.folder', 'public/avatars')
                ->where('files.0.size', 2048)
                ->where('files.0.picture', true)
                ->where('files.1.name', 'march.pdf')
                ->where('files.1.picture', false)
                ->where('files.2.picture', false)));

        $this->assertSame(['--', 'storage/app', '200'], array_slice($this->driver->executed[0]['command'], 3));
    }

    public function test_a_picture_shows_and_any_other_file_is_only_downloaded()
    {
        $this->actingAs($this->owner)
            ->get(route('preview-files.show', ['project' => $this->project, 'path' => 'public/avatars/jane.png']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename=jane.png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('PNG-BYTES');

        // A drawing can hold a script, so it never shows on the builder's pages.
        $response = $this->get(route('preview-files.show', ['project' => $this->project, 'path' => 'public/logo.svg']));
        $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Content-Disposition', 'attachment; filename=logo.svg');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_only_listed_files_are_read_and_only_by_people_who_can_see_the_app()
    {
        $this->actingAs($this->owner)
            ->get(route('preview-files.show', ['project' => $this->project, 'path' => '../../.env']))
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get(route('preview-files.show', ['project' => $this->project, 'path' => 'public/avatars/jane.png']))
            ->assertForbidden();
    }
}
