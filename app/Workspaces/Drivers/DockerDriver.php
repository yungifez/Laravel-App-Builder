<?php

namespace App\Workspaces\Drivers;

use App\Workspaces\CommandResult;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Runs workspaces as local Docker containers with hard resource ceilings.
 *
 * Containers share the host kernel, so this driver is for development and CI
 * against trusted fixtures only, never for untrusted customer code.
 */
class DockerDriver implements WorkspaceDriver
{
    /**
     * Exit code returned by coreutils `timeout` when the command ran out of time.
     */
    protected const TIMEOUT_EXIT_CODE = 124;

    public function __construct(
        protected string $binary,
        protected string $network,
        protected string $workdir,
    ) {}

    /**
     * Start a capped, unprivileged container that idles until commands arrive.
     */
    public function create(WorkspaceSpec $spec): string
    {
        $result = Process::run([
            $this->binary, 'run', '--detach', '--init',
            '--name', $spec->name,
            '--label', 'builder.workspace=1',
            '--cpus', (string) $spec->cpus,
            '--memory', "{$spec->memoryMb}m",
            '--memory-swap', "{$spec->memoryMb}m",
            '--pids-limit', (string) $spec->pids,
            '--network', $this->network,
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--workdir', $this->workdir,
            $spec->image,
            'sleep', 'infinity',
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Could not start workspace container: '.trim($result->errorOutput()));
        }

        return trim($result->output());
    }

    /**
     * Run a command in the container under coreutils `timeout`, so the process
     * inside the container is killed too, not just the local docker client.
     */
    public function exec(string $workspaceId, array $command, int $timeoutSeconds): CommandResult
    {
        $startedAt = hrtime(true);

        $result = Process::timeout($timeoutSeconds + 30)->run([
            $this->binary, 'exec', $workspaceId,
            'timeout', '--kill-after=5', "{$timeoutSeconds}s",
            ...$command,
        ]);

        $exitCode = $result->exitCode() ?? 1;

        return new CommandResult(
            exitCode: $exitCode,
            output: $result->output(),
            errorOutput: $result->errorOutput(),
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
            timedOut: $exitCode === self::TIMEOUT_EXIT_CODE,
        );
    }

    /**
     * Stream the directory into the container as a tar archive.
     */
    public function copyDirectory(string $workspaceId, string $sourcePath): void
    {
        $result = Process::run([
            'sh', '-c', 'tar -C "$1" '.CopyExclusions::tarFlags().' -cf - . | "$2" exec --interactive "$3" tar -C "$4" -xf -',
            'sh', $sourcePath, $this->binary, $workspaceId, $this->workdir,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Could not copy the project into the workspace: '.trim($result->errorOutput()));
        }
    }

    /**
     * Write a file through stdin, passing the path as an argument rather than
     * interpolating it into the shell script.
     */
    public function writeFile(string $workspaceId, string $path, string $contents): void
    {
        $result = Process::input($contents)->run([
            $this->binary, 'exec', '--interactive', $workspaceId,
            'sh', '-c', 'mkdir -p "$(dirname "$1")" && cat > "$1"', 'sh', $path,
        ]);

        if ($result->failed()) {
            throw new RuntimeException("Could not write [{$path}]: ".trim($result->errorOutput()));
        }
    }

    /**
     * Read a file from the container.
     */
    public function readFile(string $workspaceId, string $path): string
    {
        $result = Process::run([$this->binary, 'exec', $workspaceId, 'cat', '--', $path]);

        if ($result->failed()) {
            throw new RuntimeException("Could not read [{$path}]: ".trim($result->errorOutput()));
        }

        return $result->output();
    }

    /**
     * Start the command detached inside the container.
     */
    public function startService(string $workspaceId, array $command, int $port): void
    {
        $result = Process::run([$this->binary, 'exec', '--detach', $workspaceId, ...$command]);

        if ($result->failed()) {
            throw new RuntimeException('Could not start the service: '.trim($result->errorOutput()));
        }
    }

    /**
     * Reach the service at the container's address. A container without a
     * network (the default) has no address, so it cannot serve previews.
     */
    public function serviceUrl(string $workspaceId, int $port): string
    {
        $result = Process::run([
            $this->binary, 'inspect', '--format', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', $workspaceId,
        ]);

        $address = trim($result->output());

        if ($result->failed() || $address === '') {
            throw new RuntimeException('The workspace container has no network address, so it cannot serve a preview. Set WORKSPACE_DOCKER_NETWORK to a network the control plane can reach.');
        }

        return "http://{$address}:{$port}";
    }

    /**
     * Remove the container and its filesystem.
     */
    public function destroy(string $workspaceId): void
    {
        $result = Process::run([$this->binary, 'rm', '--force', '--volumes', $workspaceId]);

        if ($result->failed() && ! str_contains($result->errorOutput(), 'No such container')) {
            throw new RuntimeException('Could not remove workspace container: '.trim($result->errorOutput()));
        }
    }
}
