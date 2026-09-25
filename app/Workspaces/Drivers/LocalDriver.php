<?php

namespace App\Workspaces\Drivers;

use App\Workspaces\CommandResult;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runs workspaces as directories on the control plane host, using its toolchain.
 *
 * There is no isolation beyond a scrubbed environment: commands see only the
 * variables listed in "env_passthrough", so the customer app cannot pick up
 * the control plane's database credentials or app key. Resource ceilings are
 * not enforced. Use it only for trusted fixtures in local development.
 */
class LocalDriver implements WorkspaceDriver
{
    /**
     * Exit code reported for a command that ran out of time.
     */
    protected const TIMEOUT_EXIT_CODE = 124;

    /**
     * @param  list<string>  $envPassthrough
     */
    public function __construct(
        protected string $root,
        protected array $envPassthrough,
    ) {}

    public function create(WorkspaceSpec $spec): string
    {
        File::ensureDirectoryExists($this->directory($spec->name));

        return $spec->name;
    }

    public function exec(string $workspaceId, array $command, int $timeoutSeconds): CommandResult
    {
        $startedAt = hrtime(true);

        try {
            $result = Process::path($this->directory($workspaceId))
                ->timeout($timeoutSeconds)
                ->run(['env', '-i', ...$this->environment(), ...$command]);
        } catch (ProcessTimedOutException $exception) {
            return new CommandResult(
                exitCode: self::TIMEOUT_EXIT_CODE,
                output: (string) $exception->result->output(),
                errorOutput: (string) $exception->result->errorOutput(),
                durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
                timedOut: true,
            );
        }

        return new CommandResult(
            exitCode: $result->exitCode() ?? 1,
            output: $result->output(),
            errorOutput: $result->errorOutput(),
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
        );
    }

    public function copyDirectory(string $workspaceId, string $sourcePath): void
    {
        $result = Process::run([
            'sh', '-c', 'tar -C "$1" '.CopyExclusions::tarFlags().' -cf - . | tar -C "$2" -xf -',
            'sh', $sourcePath, $this->directory($workspaceId),
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Could not copy the project into the workspace: '.trim($result->errorOutput()));
        }
    }

    public function writeFile(string $workspaceId, string $path, string $contents): void
    {
        $target = $this->path($workspaceId, $path);

        File::ensureDirectoryExists(dirname($target));
        File::put($target, $contents);
    }

    public function readFile(string $workspaceId, string $path): string
    {
        return File::get($this->path($workspaceId, $path));
    }

    public function destroy(string $workspaceId): void
    {
        File::deleteDirectory($this->directory($workspaceId));
    }

    /**
     * Get the workspace's directory, refusing identifiers that could escape the root.
     */
    protected function directory(string $workspaceId): string
    {
        if (! preg_match('/^[a-z0-9-]+$/', $workspaceId)) {
            throw new InvalidArgumentException("Invalid workspace identifier [{$workspaceId}].");
        }

        return rtrim($this->root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$workspaceId;
    }

    /**
     * Resolve a path inside the workspace, refusing paths that escape it.
     */
    protected function path(string $workspaceId, string $path): string
    {
        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException("Path [{$path}] must stay inside the workspace.");
        }

        return $this->directory($workspaceId).DIRECTORY_SEPARATOR.$path;
    }

    /**
     * Build the NAME=value pairs passed through to commands.
     *
     * @return list<string>
     */
    protected function environment(): array
    {
        $environment = [];

        foreach ($this->envPassthrough as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $environment[] = "{$name}={$value}";
            }
        }

        return $environment;
    }
}
