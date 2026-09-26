<?php

namespace Tests\Concerns;

use App\Actions\Runs\AcquireRunLease;
use App\Actions\Runs\PrepareRunWorkspace;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Runs\RunLease;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait PreparesRuns
{
    use BuildsInLocalWorkspaces;

    /**
     * Get the files that make a source a Laravel app with Inertia and Vue,
     * which is what the builder imports.
     *
     * @return array<string, string>
     */
    protected function laravelApp(): array
    {
        return [
            'artisan' => "#!/usr/bin/env php\n<?php\n",
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^13.0', 'inertiajs/inertia-laravel' => '^3.0']], JSON_THROW_ON_ERROR),
            'package.json' => json_encode(['dependencies' => ['vue' => '^3.5', '@inertiajs/vue3' => '^3.0']], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Write a small project source and return its directory.
     *
     * @param  array<string, string>  $files
     */
    protected function makeProjectSource(array $files = []): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'builder-test-source-'.Str::lower(Str::random(8));

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        $files += [
            'app/Models/Team.php' => "<?php\n\nclass Team\n{\n    public string \$name = 'Team';\n}\n",
            'config/teams.php' => "<?php\n\nreturn [\n    'owner' => ['members:invite'],\n];\n",
            'tests/Acceptance/Contract.php' => "<?php // workspace copy\n",
            '.gitignore' => "/vendor\n",
        ];

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname("{$directory}/{$path}"));
            File::put("{$directory}/{$path}", $contents);
        }

        return $directory;
    }

    /**
     * Create an implementing run with a prepared local workspace, and the
     * lease of the worker that holds it.
     *
     * @return array{0: Run, 1: RunLease}
     */
    protected function implementingRun(?string $source = null): array
    {
        $this->buildInLocalWorkspaces();

        $project = Project::factory()->create(['source_path' => $source ?? $this->makeProjectSource()]);
        $featureRequest = FeatureRequest::factory()->for($project)->create();
        $run = Run::factory()->implementing()->for($featureRequest)->create();

        $lease = app(AcquireRunLease::class)->handle($run, 'worker-a');
        $this->assertNotNull($lease);

        app(PrepareRunWorkspace::class)->handle($run, $lease);

        return [$run->refresh(), $lease];
    }

    /**
     * Get the absolute path of a file in the run's local workspace.
     */
    protected function workspaceFile(Run $run, string $path): string
    {
        return config('workspaces.drivers.local.root').DIRECTORY_SEPARATOR.$run->workspace->driver_id.DIRECTORY_SEPARATOR.$path;
    }
}
