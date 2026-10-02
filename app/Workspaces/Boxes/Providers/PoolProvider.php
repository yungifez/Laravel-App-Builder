<?php

namespace App\Workspaces\Boxes\Providers;

use App\Enums\WorkspaceStatus;
use App\Models\Runner;
use App\Models\Workspace;
use App\Workspaces\Boxes\Contracts\BoxProvider;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A pool of runners, each a machine hosted apart from the control plane
 * that holds many workspaces as folders. A new workspace goes to the online
 * runner holding the fewest. Its box name starts with the runner's name
 * ("{runner}--{workspace}"), so finding its runner needs no lookup table.
 *
 * Runners are added with `php artisan runners:add`. Each connects out with
 * its own token and reports the address its previews are reached at,
 * usually on a private network shared with the control plane.
 */
class PoolProvider implements BoxProvider
{
    /**
     * What separates the runner's name from the workspace's in a box name.
     */
    public const SEPARATOR = '--';

    public function create(WorkspaceSpec $spec): string
    {
        // A draining runner keeps its workspaces but gets no new ones.
        $runner = Runner::query()->online()->whereNull('draining_at')->get()
            ->sortBy(fn (Runner $runner) => [$this->load($runner), $runner->id])
            ->first();

        if ($runner === null) {
            throw new RuntimeException('No runner is online to hold the workspace.');
        }

        return $runner->name.self::SEPARATOR.$spec->name;
    }

    public function runnerFor(string $box): string
    {
        return Str::before($box, self::SEPARATOR);
    }

    public function authenticate(string $token): ?string
    {
        $name = Runner::query()->where('token_hash', Runner::hashToken($token))->value('name');

        return is_string($name) ? $name : null;
    }

    public function serviceUrl(string $box, int $port): string
    {
        $host = Runner::query()->where('name', $this->runnerFor($box))->value('service_host');

        if (! is_string($host) || $host === '') {
            throw new RuntimeException("Runner [{$this->runnerFor($box)}] has not said where its services are reached.");
        }

        return "http://{$host}:{$port}";
    }

    public function destroy(string $box): void {}

    /**
     * Count the workspaces a runner holds.
     */
    public function load(Runner $runner): int
    {
        return Workspace::query()
            ->where('driver', 'runner')
            ->where('driver_id', 'like', $runner->name.self::SEPARATOR.'%')
            ->whereNot('status', WorkspaceStatus::Destroyed)
            ->count();
    }
}
