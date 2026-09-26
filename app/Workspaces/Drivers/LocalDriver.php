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

    /**
     * The local driver is for trusted development use: extra environment
     * variables are passed to `env` on its command line.
     */
    public function exec(string $workspaceId, array $command, int $timeoutSeconds, array $environment = []): CommandResult
    {
        $startedAt = hrtime(true);
        $extra = array_map(fn (string $name, string $value) => "{$name}={$value}", array_keys($environment), $environment);

        try {
            $result = Process::path($this->directory($workspaceId))
                ->timeout($timeoutSeconds)
                ->run(['env', '-i', ...$this->environment(), ...$extra, ...$command]);
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

    /**
     * Start the command detached from this PHP process, with the same
     * scrubbed environment as other commands, and remember its process id.
     */
    public function startService(string $workspaceId, array $command, int $port): void
    {
        File::ensureDirectoryExists($this->servicesDirectory($workspaceId));

        $result = Process::path($this->directory($workspaceId))->run([
            'sh', '-c', 'log="$1"; pidfile="$2"; shift 2; nohup "$@" > "$log" 2>&1 < /dev/null & echo $! > "$pidfile"',
            'sh',
            $this->servicesDirectory($workspaceId).DIRECTORY_SEPARATOR."{$port}.log",
            $this->servicesDirectory($workspaceId).DIRECTORY_SEPARATOR."{$port}.pid",
            'env', '-i', ...$this->environment(), ...$command,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Could not start the service: '.trim($result->errorOutput()));
        }
    }

    public function serviceUrl(string $workspaceId, int $port): string
    {
        $this->directory($workspaceId);

        return "http://127.0.0.1:{$port}";
    }

    /**
     * Stop the workspace's services, then delete its directory.
     */
    public function destroy(string $workspaceId): void
    {
        foreach (File::glob($this->servicesDirectory($workspaceId).DIRECTORY_SEPARATOR.'*.pid') as $pidFile) {
            $pid = (int) trim((string) File::get($pidFile));

            if ($pid > 1) {
                Process::run(['kill', '-TERM', (string) $pid]);
            }
        }

        File::deleteDirectory($this->servicesDirectory($workspaceId));
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
     * Get the directory holding the workspace's service logs and process ids,
     * next to the workspace so the project never sees them.
     */
    protected function servicesDirectory(string $workspaceId): string
    {
        return $this->directory($workspaceId).'.services';
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
