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
     * Copy a local directory into the workspace's working directory, skipping
     * dependency folders, git metadata and environment files.
     */
    public function copyDirectory(string $workspaceId, string $sourcePath): void;

    /**
     * Write a file inside the workspace, creating parent directories.
     */
    public function writeFile(string $workspaceId, string $path, string $contents): void;

    /**
     * Read a file from inside the workspace.
     */
    public function readFile(string $workspaceId, string $path): string;

    /**
     * Start a long-running process in the workspace, such as the app's web
     * server, listening on the given port. It runs until the workspace is
     * destroyed.
     *
     * @param  list<string>  $command
     */
    public function startService(string $workspaceId, array $command, int $port): void;

    /**
     * Get the base URL the control plane reaches a service in the workspace at.
     */
    public function serviceUrl(string $workspaceId, int $port): string;

    /**
     * Stop the workspace and delete everything in it, including its services.
     */
    public function destroy(string $workspaceId): void;
}
