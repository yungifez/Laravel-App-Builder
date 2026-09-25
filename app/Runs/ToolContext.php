<?php

namespace App\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Run;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Runs\Exceptions\ToolFailed;
use App\Runs\Exceptions\ToolRejected;
use App\Workspaces\Contracts\WorkspaceDriver;
use Illuminate\Support\Str;

/**
 * What a tool may touch: the run's own workspace, through paths that are
 * checked against the protected paths before any read or write.
 */
class ToolContext
{
    /**
     * @param  list<string>  $protectedPaths
     */
    public function __construct(
        public readonly Run $run,
        public readonly Workspace $workspace,
        protected WorkspaceDriver $driver,
        protected RunWorkspaceCommand $runWorkspaceCommand,
        protected array $protectedPaths,
    ) {}

    /**
     * Normalize a path relative to the workspace root.
     *
     * @throws ToolRejected for absolute paths, parent references or control characters.
     */
    public function path(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));

        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/[\x00-\x1F]/', $normalized)) {
            throw new ToolRejected(__('The path [:path] must be relative to the project root.', ['path' => $path]));
        }

        $segments = array_values(array_filter(explode('/', $normalized), fn (string $segment) => $segment !== '' && $segment !== '.'));

        if ($segments === [] || in_array('..', $segments, true)) {
            throw new ToolRejected(__('The path [:path] must stay inside the project.', ['path' => $path]));
        }

        return implode('/', $segments);
    }

    /**
     * Determine if the path is, or is inside, a protected path.
     */
    public function isProtected(string $path): bool
    {
        foreach ($this->protectedPaths as $protected) {
            $protected = trim($protected, '/');

            if (Str::lower($path) === Str::lower($protected) || Str::startsWith(Str::lower($path), Str::lower($protected).'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a path the tool may read, following symbolic links, and refuse
     * one that leads outside the workspace.
     *
     * @throws ToolRejected
     */
    public function readablePath(string $path): string
    {
        $path = $this->path($path);
        $command = $this->run(['realpath', '-m', '--relative-base=.', '--', $path], 30);
        $resolved = trim($command->output);

        if ($command->exit_code !== 0 || $resolved === '' || str_starts_with($resolved, '/')) {
            throw new ToolRejected(__('The path [:path] leads outside the project.', ['path' => $path]));
        }

        return $resolved;
    }

    /**
     * Resolve a path the tool may write, refusing protected paths.
     *
     * @throws ToolRejected
     */
    public function writablePath(string $path): string
    {
        $path = $this->path($path);

        if ($this->isProtected($path)) {
            throw new ToolRejected(__('The path [:path] is protected and cannot be changed.', ['path' => $path]));
        }

        $resolved = $this->readablePath($path);

        if ($this->isProtected($resolved)) {
            throw new ToolRejected(__('The path [:path] is protected and cannot be changed.', ['path' => $path]));
        }

        return $resolved;
    }

    /**
     * Determine if a file exists at the (already checked) path.
     */
    public function exists(string $path): bool
    {
        return $this->run(['test', '-f', $path], 30)->exit_code === 0;
    }

    /**
     * Read a file at an already checked path.
     *
     * @throws ToolFailed when the file does not exist.
     */
    public function read(string $path): string
    {
        if (! $this->exists($path)) {
            throw new ToolFailed(__('The file [:path] does not exist.', ['path' => $path]));
        }

        return $this->driver->readFile((string) $this->workspace->driver_id, $path);
    }

    /**
     * Write a file at an already checked path.
     */
    public function write(string $path, string $contents): void
    {
        $this->driver->writeFile((string) $this->workspace->driver_id, $path, $contents);
    }

    /**
     * Run a command in the workspace.
     *
     * @param  list<string>  $command
     */
    public function run(array $command, int $timeoutSeconds): WorkspaceCommand
    {
        return $this->runWorkspaceCommand->handle($this->workspace, $command, $timeoutSeconds);
    }

    /**
     * Cut long text to the configured output limit, keeping its start, or its
     * end where results usually are (for example test output).
     */
    public function bound(string $text, bool $keepEnd = false): string
    {
        $limit = (int) config('builder.construction.limits.output_characters');

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return $keepEnd ? '…'.Str::substr($text, -$limit) : Str::substr($text, 0, $limit).'…';
    }
}
