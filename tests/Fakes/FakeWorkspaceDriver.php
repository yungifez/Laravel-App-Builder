<?php

namespace Tests\Fakes;

use App\Workspaces\CommandResult;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\WorkspaceSpec;
use Closure;
use RuntimeException;

class FakeWorkspaceDriver implements WorkspaceDriver
{
    /** @var list<WorkspaceSpec> */
    public array $created = [];

    /** @var list<array{workspace: string, command: list<string>, timeout: int}> */
    public array $executed = [];

    /** @var list<string> */
    public array $destroyed = [];

    /** @var array<string, string> */
    public array $files = [];

    public bool $failCreate = false;

    /** @var (Closure(string, list<string>, int): CommandResult)|null */
    public ?Closure $onExec = null;

    public function create(WorkspaceSpec $spec): string
    {
        if ($this->failCreate) {
            throw new RuntimeException('Could not start workspace.');
        }

        $this->created[] = $spec;

        return 'fake-'.count($this->created);
    }

    public function exec(string $workspaceId, array $command, int $timeoutSeconds): CommandResult
    {
        $this->executed[] = ['workspace' => $workspaceId, 'command' => $command, 'timeout' => $timeoutSeconds];

        return $this->onExec !== null
            ? ($this->onExec)($workspaceId, $command, $timeoutSeconds)
            : new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
    }

    public function writeFile(string $workspaceId, string $path, string $contents): void
    {
        $this->files["{$workspaceId}:{$path}"] = $contents;
    }

    public function readFile(string $workspaceId, string $path): string
    {
        return $this->files["{$workspaceId}:{$path}"] ?? throw new RuntimeException("No such file [{$path}].");
    }

    public function destroy(string $workspaceId): void
    {
        $this->destroyed[] = $workspaceId;
    }
}
