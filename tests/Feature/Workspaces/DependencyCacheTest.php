<?php

namespace Tests\Feature\Workspaces;

use App\Models\Preview;
use App\Models\Project;
use App\Models\Verification;
use App\Models\Workspace;
use App\Workspaces\Drivers\RunnerDriver;
use App\Workspaces\WorkspaceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Process;
use Tests\Fakes\FakeBoxRunner;
use Tests\TestCase;

/**
 * An install a setup step marks is warmed on the runner from its own app's
 * dependency cache (resources/box-runner/dependency-cache.mjs). The control
 * plane names the app and the package manager; the runner does the rest.
 */
class DependencyCacheTest extends TestCase
{
    use RefreshDatabase;

    protected const COMPOSER = ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'];

    protected RunnerDriver $driver;

    /** @var list<array<string, mixed>> */
    protected array $payloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $runner = new FakeBoxRunner('local');
        Broadcast::extend('fake-runner', fn () => $runner);

        config([
            'broadcasting.default' => 'fake-runner',
            'broadcasting.connections.fake-runner' => ['driver' => 'fake-runner'],
            'workspaces.boxes.static' => ['runner' => 'local', 'token' => 'runner-token', 'service_host' => 'runner'],
            'workspaces.drivers.runner.answer_seconds' => 1,
            'workspaces.drivers.runner.grace_seconds' => 0,
            'workspaces.drivers.runner.poll_ms' => 10,
        ]);

        $runner->on('exec', function (array $payload) {
            $this->payloads[] = $payload;

            return ['exit_code' => 0, 'output' => '', 'error_output' => '', 'timed_out' => false, 'duration_ms' => 1];
        });

        $driver = $this->app->make(WorkspaceManager::class)->driver('runner');
        $this->assertInstanceOf(RunnerDriver::class, $driver);
        $this->driver = $driver;
    }

    public function test_a_marked_install_names_its_app_and_package_manager(): void
    {
        $verification = Verification::factory()->create(['workspace_id' => $this->workspace('workspace-check')->id]);
        /** @var list<array{command: list<string>, cache?: string}> $setup */
        $setup = config('builder.verification.setup');
        $npm = collect($setup)->firstWhere('cache', 'npm')['command'] ?? [];

        $this->driver->exec('workspace-check', self::COMPOSER, 60);
        $this->driver->exec('workspace-check', $npm, 60);

        $project = "project-{$verification->featureRequest->project_id}";
        $this->assertSame(['scope' => $project, 'kind' => 'composer'], $this->payloads[0]['cache']);
        $this->assertSame(['scope' => $project, 'kind' => 'npm'], $this->payloads[1]['cache']);
    }

    public function test_each_app_has_its_own_cache_and_other_commands_use_none(): void
    {
        $verification = Verification::factory()->create(['workspace_id' => $this->workspace('workspace-check')->id]);
        $preview = Preview::factory()->create([
            'feature_request_id' => null,
            'project_id' => Project::factory()->create()->id,
            'workspace_id' => $this->workspace('workspace-preview')->id,
        ]);

        $this->driver->exec('workspace-check', self::COMPOSER, 60);
        $this->driver->exec('workspace-preview', self::COMPOSER, 60);
        $this->driver->exec('workspace-preview', ['php', 'artisan', 'key:generate', '--no-interaction'], 60);
        // The same program with other options is not the step.
        $this->driver->exec('workspace-preview', ['composer', 'install'], 60);

        $this->assertSame("project-{$verification->featureRequest->project_id}", $this->payloads[0]['cache']['scope']);
        $this->assertSame("project-{$preview->project_id}", $this->payloads[1]['cache']['scope']);
        $this->assertNotSame($this->payloads[0]['cache']['scope'], $this->payloads[1]['cache']['scope']);
        $this->assertArrayNotHasKey('cache', $this->payloads[2]);
        $this->assertArrayNotHasKey('cache', $this->payloads[3]);
    }

    public function test_a_workspace_that_serves_no_app_gets_no_cache(): void
    {
        $this->workspace('workspace-loose');

        $this->driver->exec('workspace-loose', self::COMPOSER, 60);
        $this->driver->exec('workspace-unknown', self::COMPOSER, 60);

        $this->assertArrayNotHasKey('cache', $this->payloads[0]);
        $this->assertArrayNotHasKey('cache', $this->payloads[1]);
    }

    public function test_the_runner_is_told_where_the_cache_is_and_how_large_it_may_grow(): void
    {
        config([
            'workspaces.drivers.runner.dependency_cache.directory' => '.packages',
            'workspaces.drivers.runner.dependency_cache.limit_mb' => 2048,
        ]);

        $this->withToken('runner-token')->postJson('/api/runner/hello')
            ->assertOk()
            ->assertJsonPath('dependency_cache', ['directory' => '.packages', 'limit_mb' => 2048]);
    }

    public function test_the_real_runner_warms_installs_from_the_apps_own_cache(): void
    {
        // As root, the runner's way in its box, it also checks that each
        // workspace gets the packages as its own user and cannot reach the
        // cache: run tests/Fixtures/box-runner-cache.mjs there too.
        $result = Process::timeout(90)->run(['node', base_path('tests/Fixtures/box-runner-cache.mjs'), base_path('resources/box-runner/runner.mjs')]);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }

    protected function workspace(string $box): Workspace
    {
        return Workspace::factory()->create(['driver' => 'runner', 'driver_id' => $box]);
    }
}
