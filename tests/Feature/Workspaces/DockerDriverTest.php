<?php

namespace Tests\Feature\Workspaces;

use App\Workspaces\Drivers\DockerDriver;
use App\Workspaces\WorkspaceManager;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class DockerDriverTest extends TestCase
{
    public function test_the_manager_builds_the_docker_driver_from_config()
    {
        config(['workspaces.default' => 'docker']);

        $this->assertInstanceOf(DockerDriver::class, app(WorkspaceManager::class)->driver());
    }

    public function test_containers_start_with_hard_resource_ceilings_and_no_privileges()
    {
        Process::fake(['*' => Process::result(output: "abc123\n")]);

        $id = $this->driver()->create(new WorkspaceSpec('workspace-test', 'php:8.4-cli', cpus: 1.5, memoryMb: 1024, pids: 256));

        $this->assertSame('abc123', $id);
        Process::assertRan(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return str_starts_with($command, 'docker run --detach --init --name workspace-test')
                && str_contains($command, '--cpus 1.5')
                && str_contains($command, '--memory 1024m --memory-swap 1024m')
                && str_contains($command, '--pids-limit 256')
                && str_contains($command, '--network none')
                && str_contains($command, '--cap-drop ALL')
                && str_contains($command, '--security-opt no-new-privileges')
                && str_ends_with($command, 'php:8.4-cli sleep infinity');
        });
    }

    public function test_commands_are_killed_inside_the_container_when_they_time_out()
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 124)]);

        $result = $this->driver()->exec('abc123', ['php', 'artisan', 'test'], 60);

        $this->assertTrue($result->timedOut);
        $this->assertFalse($result->successful());
        Process::assertRan(fn (PendingProcess $process) => (array) $process->command === [
            'docker', 'exec', 'abc123', 'timeout', '--kill-after=5', '60s', 'php', 'artisan', 'test',
        ] && $process->timeout === 90);
    }

    public function test_file_paths_are_passed_as_arguments_not_interpolated_into_the_shell()
    {
        Process::fake();

        $this->driver()->writeFile('abc123', 'app/$(rm -rf /).php', '<?php');

        Process::assertRan(function (PendingProcess $process) {
            $command = (array) $process->command;

            return $command[6] === 'mkdir -p "$(dirname "$1")" && cat > "$1"'
                && end($command) === 'app/$(rm -rf /).php'
                && $process->input === '<?php';
        });
    }

    public function test_a_failed_start_is_reported()
    {
        Process::fake(['*' => Process::result(errorOutput: 'no such image', exitCode: 125)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no such image');

        $this->driver()->create(new WorkspaceSpec('workspace-test', 'missing', 1, 512, 64));
    }

    protected function driver(): DockerDriver
    {
        return new DockerDriver(binary: 'docker', network: 'none', workdir: '/workspace');
    }
}
