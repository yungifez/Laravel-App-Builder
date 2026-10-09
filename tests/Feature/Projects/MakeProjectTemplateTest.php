<?php

namespace Tests\Feature\Projects;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class MakeProjectTemplateTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/builder-template-'.uniqid();
        config(['builder.projects.template' => $this->path, 'builder.projects.template_package' => 'laravel/vue-starter-kit']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    public function test_it_gets_the_template_package_and_its_lock_files_without_its_dependencies()
    {
        Process::fake();

        $this->artisan('projects:template')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            'composer', 'create-project', 'laravel/vue-starter-kit', $this->path,
            '--no-install', '--no-scripts', '--no-interaction', '--prefer-dist',
        ]);
        Process::assertRan(fn (PendingProcess $process) => $process->path === $this->path
            && $process->command === ['composer', 'update', '--no-install', '--no-scripts', '--no-interaction', '--no-progress']);
        Process::assertRan(fn (PendingProcess $process) => $process->path === $this->path
            && $process->command === ['npm', 'install', '--package-lock-only', '--no-audit', '--no-fund']);
    }

    public function test_a_template_already_in_place_is_kept_unless_forced()
    {
        Process::fake();
        File::ensureDirectoryExists($this->path);
        File::put($this->path.'/marker', 'kept');

        $this->artisan('projects:template')->assertSuccessful();
        Process::assertNothingRan();
        $this->assertFileExists($this->path.'/marker');

        $this->artisan('projects:template --force')->assertSuccessful();
        Process::assertRanTimes(fn (PendingProcess $process) => $process->command[1] === 'create-project', 1);
        $this->assertFileDoesNotExist($this->path.'/marker');
    }

    public function test_a_failed_download_leaves_no_half_made_template()
    {
        Process::fake(fn () => Process::result(errorOutput: 'Could not find package', exitCode: 1));

        $this->artisan('projects:template')->assertFailed();

        $this->assertDirectoryDoesNotExist($this->path);
    }
}
