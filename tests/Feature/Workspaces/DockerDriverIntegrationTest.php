<?php

namespace Tests\Feature\Workspaces;

use App\Workspaces\Drivers\DockerDriver;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runs against a real Docker daemon. Opt in with WORKSPACE_DOCKER_TESTS=1.
 */
class DockerDriverIntegrationTest extends TestCase
{
    protected DockerDriver $driver;

    protected ?string $workspaceId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('WORKSPACE_DOCKER_TESTS')) {
            $this->markTestSkipped('Set WORKSPACE_DOCKER_TESTS=1 to run against a real Docker daemon.');
        }

        $this->driver = new DockerDriver(binary: 'docker', network: 'none', workdir: '/workspace');
        $this->workspaceId = $this->driver->create(new WorkspaceSpec(
            name: 'workspace-test-'.Str::lower(Str::random(8)),
            image: (string) (env('WORKSPACE_DOCKER_TEST_IMAGE') ?: 'php:8.4-cli'),
            cpus: 0.5,
            memoryMb: 256,
            pids: 64,
        ));
    }

    protected function tearDown(): void
    {
        if ($this->workspaceId !== null) {
            $this->driver->destroy($this->workspaceId);
        }

        parent::tearDown();
    }

    public function test_the_container_runs_with_the_requested_ceilings()
    {
        $inspect = Process::run(['docker', 'inspect', '--format', '{{.HostConfig.NanoCpus}} {{.HostConfig.Memory}} {{.HostConfig.PidsLimit}} {{.HostConfig.NetworkMode}}', (string) $this->workspaceId]);

        $this->assertSame('500000000 268435456 64 none', trim($inspect->output()));
    }

    public function test_commands_run_and_files_round_trip()
    {
        $result = $this->driver->exec((string) $this->workspaceId, ['php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'], 30);

        $this->assertTrue($result->successful());
        $this->assertSame('8.4', $result->output);

        $this->driver->writeFile((string) $this->workspaceId, '/workspace/nested/hello.txt', 'hello');
        $this->assertSame('hello', $this->driver->readFile((string) $this->workspaceId, '/workspace/nested/hello.txt'));
    }

    public function test_runaway_commands_are_killed_at_the_timeout()
    {
        $result = $this->driver->exec((string) $this->workspaceId, ['sleep', '30'], 1);

        $this->assertTrue($result->timedOut);
        $this->assertLessThan(10_000, $result->durationMs);
    }
}
