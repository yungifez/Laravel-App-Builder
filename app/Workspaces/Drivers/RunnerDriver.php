<?php

namespace App\Workspaces\Drivers;

use App\Enums\BoxCommandStatus;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Run;
use App\Models\Verification;
use App\Models\Workspace;
use App\Workspaces\Boxes\BoxChannel;
use App\Workspaces\Boxes\Contracts\BoxProvider;
use App\Workspaces\CommandResult;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\WorkspaceSpec;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs each workspace in a box with a runner in it. The provider only
 * creates and destroys boxes; commands, files and services go through the
 * runner, so this driver works the same with any provider.
 *
 * The box holds nothing of the control plane: no code, no .env and no
 * database access. Credentials reach a single command, as with the other
 * drivers, until the model gateway takes them out of the box too.
 */
class RunnerDriver implements WorkspaceDriver
{
    /**
     * Exit code reported for a command that ran out of time or was lost.
     */
    protected const TIMEOUT_EXIT_CODE = 124;

    public function __construct(
        protected BoxChannel $channel,
        protected BoxProvider $provider,
        protected int $fileSeconds,
    ) {}

    public function create(WorkspaceSpec $spec): string
    {
        $box = $this->provider->create($spec);

        try {
            $this->channel->call($box, 'open', [], $this->fileSeconds);
        } catch (Throwable $exception) {
            // The box may be half made on its machine, and no workspace
            // records it to close it later. Ask its runner to clear it when
            // it can, without waiting: a runner that is gone would only
            // hold this failure up.
            rescue(fn () => $this->channel->send($box, 'close', [], $this->fileSeconds));

            throw $exception;
        }

        return $box;
    }

    public function exec(string $workspaceId, array $command, int $timeoutSeconds, array $environment = [], ?Closure $whileRunning = null): CommandResult
    {
        $payload = ['command' => $command, 'env' => $environment];

        if (($cache = $this->dependencyCache($workspaceId, $command)) !== null) {
            $payload['cache'] = $cache;
        }

        $sent = $this->channel->send($workspaceId, 'exec', $payload, $timeoutSeconds);
        $finished = $this->channel->await($sent, $whileRunning);
        $result = $finished->result ?? [];

        if ($finished->status === BoxCommandStatus::Lost) {
            return new CommandResult(
                exitCode: self::TIMEOUT_EXIT_CODE,
                output: '',
                errorOutput: (string) ($result['error_output'] ?? ''),
                durationMs: (int) $finished->created_at?->diffInMilliseconds($finished->finished_at, true),
                timedOut: true,
                lost: true,
            );
        }

        return new CommandResult(
            exitCode: (int) ($result['exit_code'] ?? 1),
            output: (string) ($result['output'] ?? ''),
            errorOutput: (string) ($result['error_output'] ?? ''),
            durationMs: (int) ($result['duration_ms'] ?? 0),
            timedOut: (bool) ($result['timed_out'] ?? false),
        );
    }

    /**
     * Pack the directory here, without the excluded paths, and let the
     * runner fetch and unpack it.
     */
    public function copyDirectory(string $workspaceId, string $sourcePath): void
    {
        $archive = Str::lower((string) Str::ulid()).'.tar.gz';
        $path = self::archivePath($archive);

        File::ensureDirectoryExists(dirname($path));

        $packed = Process::run(['sh', '-c', 'tar -C "$1" '.CopyExclusions::tarFlags().' -czf "$2" .', 'sh', $sourcePath, $path]);

        if ($packed->failed()) {
            throw new RuntimeException('Could not pack the project for the workspace: '.trim($packed->errorOutput()));
        }

        try {
            $this->channel->call($workspaceId, 'unpack', ['archive' => $archive], (int) config('workspaces.commands.timeout'));
        } finally {
            File::delete($path);
        }
    }

    public function writeFile(string $workspaceId, string $path, string $contents): void
    {
        $this->channel->call($workspaceId, 'write', ['path' => $this->relative($path), 'contents' => base64_encode($contents)], $this->fileSeconds);
    }

    public function readFile(string $workspaceId, string $path, ?int $tailBytes = null): string
    {
        $result = $this->channel->call($workspaceId, 'read', [
            'path' => $this->relative($path),
            ...($tailBytes === null ? [] : ['tail_bytes' => max(0, $tailBytes)]),
        ], $this->fileSeconds);

        return (string) base64_decode((string) ($result['contents'] ?? ''), true);
    }

    public function startService(string $workspaceId, array $command, int $port): void
    {
        $this->channel->call($workspaceId, 'start_service', ['command' => $command, 'port' => $port], $this->fileSeconds);
    }

    public function serviceUrl(string $workspaceId, int $port): string
    {
        return $this->provider->serviceUrl($workspaceId, $port);
    }

    public function destroy(string $workspaceId): void
    {
        try {
            $this->channel->call($workspaceId, 'close', [], $this->fileSeconds);
        } finally {
            $this->provider->destroy($workspaceId);
        }
    }

    /**
     * Where a packed project waits for its runner to fetch it.
     */
    public static function archivePath(string $archive): string
    {
        if (! preg_match('/^[a-z0-9]+\.tar\.gz$/', $archive)) {
            throw new InvalidArgumentException("Invalid archive name [{$archive}].");
        }

        return storage_path("app/private/box-archives/{$archive}");
    }

    /**
     * Refuse paths that leave the workspace. The runner checks again.
     */
    protected function relative(string $path): string
    {
        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException("Path [{$path}] must stay inside the workspace.");
        }

        return $path;
    }

    /**
     * Name the app and the package manager when the command is an install
     * a setup step marks for the dependency cache, so the runner can warm
     * it from that app's cache and keeps each app's cache apart. Any other
     * command, or a workspace that serves no app, goes without.
     *
     * @param  list<string>  $command
     * @return array{scope: string, kind: string}|null
     */
    protected function dependencyCache(string $workspaceId, array $command): ?array
    {
        $kind = null;

        foreach ([...Config::array('builder.verification.setup'), ...Config::array('builder.preview.setup')] as $step) {
            if (is_array($step) && is_string($step['cache'] ?? null) && ($step['command'] ?? null) === $command) {
                $kind = $step['cache'];

                break;
            }
        }

        if ($kind === null) {
            return null;
        }

        $workspace = Workspace::query()->where('driver_id', $workspaceId)->value('id');

        if ($workspace === null) {
            return null;
        }

        $project = Preview::query()->where('workspace_id', $workspace)->value('project_id')
            ?? FeatureRequest::query()->whereIn('id', Verification::query()->where('workspace_id', $workspace)->select('feature_request_id'))->value('project_id')
            ?? FeatureRequest::query()->whereIn('id', Run::query()->where('workspace_id', $workspace)->select('feature_request_id'))->value('project_id');

        return $project === null ? null : ['scope' => "project-{$project}", 'kind' => $kind];
    }
}
