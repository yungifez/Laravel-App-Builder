<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ProjectCheckoutTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_a_checkout_has_every_file_even_those_the_app_leaves_out_of_its_releases()
    {
        $repository = app(ProjectRepository::class);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource([
            '.gitattributes' => "CHANGELOG.md export-ignore\n/.github export-ignore\nVERSION export-subst\n",
            'CHANGELOG.md' => "# Changes\n",
            '.github/workflows/tests.yml' => "on: push\n",
            'VERSION' => "\$Format:%H\$\n",
        ])]);
        $repository->import($project);

        $files = $repository->withCheckout($project, $repository->head($project), fn (string $directory) => [
            File::get("{$directory}/CHANGELOG.md"),
            File::exists("{$directory}/.github/workflows/tests.yml"),
            File::get("{$directory}/VERSION"),
        ]);

        $this->assertSame(["# Changes\n", true, "\$Format:%H\$\n"], $files);
    }
}
