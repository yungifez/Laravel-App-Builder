<?php

namespace Tests\Feature\Projects;

use App\Actions\Operations\FindAttentionItems;
use App\Models\Project;
use App\Projects\Exceptions\RepositoryMissing;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class LostRepositoryTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_a_repository_made_once_is_never_made_again_from_the_source()
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);

        $repository->import($project);
        $this->assertNotNull($project->refresh()->repository_created_at);

        // Lost, for example by a restore that left the repositories out.
        File::deleteDirectory($repository->path($project));

        try {
            $repository->import($project);
            $this->fail('A lost repository was made again from the source.');
        } catch (RepositoryMissing $exception) {
            $this->assertStringStartsWith('This is our fault', $exception->getMessage());
        }

        $this->assertFalse($repository->exists($project));
    }

    public function test_reading_a_lost_repository_says_it_is_missing()
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $repository->import($project);
        File::deleteDirectory($repository->path($project));

        $this->expectException(RepositoryMissing::class);

        $repository->head($project->refresh());
    }

    public function test_a_repository_made_before_it_was_recorded_is_recorded_on_first_use()
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $head = $repository->import($project);
        $project->forceFill(['repository_created_at' => null])->save();

        $this->assertSame($head, $repository->import($project->refresh()));
        $this->assertNotNull($project->refresh()->repository_created_at);
    }

    public function test_operators_see_apps_whose_saved_code_is_missing()
    {
        $repository = app(ProjectRepository::class);
        $kept = Project::factory()->create(['name' => 'Kept', 'source_path' => $this->makeProjectSource()]);
        $lost = Project::factory()->create(['name' => 'Lost', 'source_path' => $this->makeProjectSource()]);
        $repository->import($kept);
        $repository->import($lost);
        // Never imported: nothing is missing yet.
        Project::factory()->create(['name' => 'New']);

        File::deleteDirectory($repository->path($lost));

        $item = collect(app(FindAttentionItems::class)->handle(7)['items'])->firstWhere('key', 'repositories_missing');

        $this->assertSame(1, $item['count']);
        $this->assertSame('Lost', $item['records'][0]['label']);
    }
}
