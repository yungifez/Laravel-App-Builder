<?php

namespace App\Workspaces\Contracts;

use App\Workspaces\CommandResult;
use App\Workspaces\WorkspaceSpec;

interface WorkspaceDriver
{
    /**
     * Start a workspace and return the driver's identifier for it.
     */
    public function create(WorkspaceSpec $spec): string;

    /**
     * Run a command inside the workspace, killing it after the timeout.
     *
     * @param  list<string>  $command
     */
    public function exec(string $workspaceId, array $command, int $timeoutSeconds): CommandResult;

    /**
     * Write a file inside the workspace, creating parent directories.
     */
    public function writeFile(string $workspaceId, string $path, string $contents): void;

    /**
     * Read a file from inside the workspace.
     */
    public function readFile(string $workspaceId, string $path): string;

    /**
     * Stop the workspace and delete everything in it.
     */
    public function destroy(string $workspaceId): void;
}
