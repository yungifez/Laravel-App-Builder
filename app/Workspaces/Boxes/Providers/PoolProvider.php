<?php

namespace App\Workspaces\Boxes\Providers;

use App\Enums\WorkspaceStatus;
use App\Models\Runner;
use App\Models\Workspace;
use App\Workspaces\Boxes\Contracts\BoxProvider;
use App\Workspaces\WorkspaceSpec;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
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

    /**
     * Where boxes placed but not yet recorded on their workspace are kept.
     * Opening a box takes a few seconds, and only then does its workspace
     * record its name; until then, the reservation counts toward the load.
     */
    protected const RESERVATIONS = 'workspaces:pool:reservations';

    /**
     * How long a reservation counts: longer than opening a box may take.
     */
    protected const RESERVATION_SECONDS = 300;

    /**
     * Where workspaces waiting for room are kept, so the pool on a cloud
     * starts machines for them too.
     */
    protected const WAITING = 'workspaces:pool:waiting';

    public function create(WorkspaceSpec $spec): string
    {
        // On a cloud, a full pool starts a machine within minutes, so the
        // workspace waits for it. Without one, more room comes only when
        // someone adds a machine.
        $deadline = now()->addMinutes(config('workspaces.machines.cloud') ? (int) config('workspaces.machines.boot_minutes') : 0);
        $waiter = null;

        try {
            while (true) {
                $box = $this->place($spec);

                if ($box !== null) {
                    return $box;
                }

                if (now()->gte($deadline)) {
                    throw new RuntimeException('No runner is online with room to hold the workspace.');
                }

                $waiter ??= Str::random(16);
                $this->wait($waiter, $deadline->getTimestamp());

                // The one who asked keeps its own claims alive meanwhile, or
                // stops the wait by throwing.
                if ($spec->whileWaiting !== null) {
                    ($spec->whileWaiting)();
                }

                Sleep::for(5)->seconds();
            }
        } finally {
            if ($waiter !== null) {
                $this->placing(fn () => Cache::put(self::WAITING, array_diff_key($this->waiters(), [$waiter => true]), self::RESERVATION_SECONDS));
            }
        }
    }

    /**
     * Count the workspaces waiting for room.
     */
    public function waiting(): int
    {
        return count($this->waiters());
    }

    /**
     * Put a box for the workspace on the online runner holding the fewest,
     * or return null when none has room.
     */
    protected function place(WorkspaceSpec $spec): ?string
    {
        // Workspaces asked for at the same moment are placed one at a time,
        // each seeing the ones placed before it.
        return $this->placing(function () use ($spec) {
            $limit = (int) config('workspaces.boxes.pool.max_workspaces');

            // A draining runner keeps its workspaces but gets no new ones; a
            // full one gets none until some close, and one whose disk is
            // nearly full none until it has room. A runner that has not said
            // how much disk it has gets them.
            $runner = Runner::query()->online()->whereNull('draining_at')
                ->where(fn (Builder $query) => $query->whereNull('disk_free_mb')->orWhere('disk_free_mb', '>=', (int) config('workspaces.boxes.pool.min_free_disk_mb')))
                ->get()
                ->map(fn (Runner $runner) => ['runner' => $runner, 'load' => $this->load($runner)])
                ->reject(fn (array $candidate) => $limit > 0 && $candidate['load'] >= $limit)
                ->sortBy(fn (array $candidate) => [$candidate['load'], $candidate['runner']->id])
                ->first()['runner'] ?? null;

            if ($runner === null) {
                return null;
            }

            $box = $runner->name.self::SEPARATOR.$spec->name;
            Cache::put(self::RESERVATIONS, [...$this->reservations(), $box => now()->addSeconds(self::RESERVATION_SECONDS)->getTimestamp()], self::RESERVATION_SECONDS);

            return $box;
        });
    }

    /**
     * Run a step that must not overlap with placing workspaces, such as
     * taking a machine out of the pool, so no workspace lands on a machine
     * as it goes.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $step
     * @return TResult
     */
    public function placing(Closure $step): mixed
    {
        return Cache::lock(self::RESERVATIONS.':lock', 30)->block(15, $step);
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
     * Count the workspaces a runner holds, and those being opened on it.
     */
    public function load(Runner $runner): int
    {
        $opening = collect($this->reservations())->keys()
            ->filter(fn (string $box) => $this->runnerFor($box) === $runner->name);

        if ($opening->isNotEmpty()) {
            // Once recorded on a workspace, in any state, a box counts there
            // or no longer counts at all.
            $opening = $opening->diff(Workspace::query()->whereIn('driver_id', $opening)->pluck('driver_id'));
        }

        return $this->workspacesOn($runner)->count() + $opening->count();
    }

    /**
     * Get the boxes placed recently, with when each reservation ends.
     *
     * @return array<string, int>
     */
    protected function reservations(): array
    {
        $reservations = Cache::get(self::RESERVATIONS, []);

        return array_filter(is_array($reservations) ? $reservations : [], fn (mixed $until) => is_int($until) && $until > now()->getTimestamp());
    }

    /**
     * Note that a workspace waits for room, until the given time.
     */
    protected function wait(string $waiter, int $until): void
    {
        $this->placing(fn () => Cache::put(self::WAITING, [...$this->waiters(), $waiter => $until], self::RESERVATION_SECONDS + 60));
    }

    /**
     * Get the workspaces waiting for room, with when each gives up.
     *
     * @return array<string, int>
     */
    protected function waiters(): array
    {
        $waiters = Cache::get(self::WAITING, []);

        return array_filter(is_array($waiters) ? $waiters : [], fn (mixed $until) => is_int($until) && $until > now()->getTimestamp());
    }

    /**
     * Get the workspaces a runner holds.
     *
     * @return Builder<Workspace>
     */
    public function workspacesOn(Runner $runner): Builder
    {
        return Workspace::query()
            ->where('driver', 'runner')
            ->where('driver_id', 'like', $runner->name.self::SEPARATOR.'%')
            ->whereNot('status', WorkspaceStatus::Destroyed);
    }
}
