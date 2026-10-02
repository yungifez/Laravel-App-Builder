<?php

namespace App\Actions\Runners;

use App\Models\Runner;
use App\Models\Workspace;
use App\Workspaces\Boxes\Providers\PoolProvider;
use App\Workspaces\Machines\Contracts\MachineCloud;
use App\Workspaces\Machines\MachineCloudManager;
use App\Workspaces\Machines\RunnerBootScript;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Grow and shrink the pool on its cloud, so it holds a few free places and
 * no machine that sits empty. Each pass changes at most one machine either
 * way: starting a machine takes minutes, and the next pass sees it.
 */
class ScaleRunnerPool
{
    /**
     * Until when no machine starts, after one whose runner never answered.
     */
    public const PAUSED_UNTIL = 'workspaces:machines:paused-until';

    public function __construct(
        private MachineCloudManager $clouds,
        private PoolProvider $pool,
        private RetireRunner $retireRunner,
        private RunnerBootScript $bootScript,
    ) {}

    /**
     * Take one pass over the pool.
     *
     * @return list<string> what the pass did, for the operator.
     */
    public function handle(): array
    {
        if (! $this->clouds->enabled()) {
            return ['The pool has no cloud, so machines are added by hand.'];
        }

        $capacity = (int) config('workspaces.boxes.pool.max_workspaces');

        if ($capacity <= 0) {
            return ['Set WORKSPACE_RUNNER_MAX_WORKSPACES, so the pool knows how many workspaces a machine holds.'];
        }

        $name = (string) $this->clouds->getDefaultDriver();
        $cloud = $this->clouds->driver($name);
        $done = [...$this->deleteStrays($name, $cloud), ...$this->retireSilent($name), ...$this->finishDraining($name)];

        $machines = Runner::query()->where('cloud', $name)->whereNull('draining_at')->get();
        $spare = $this->spare($capacity);
        $wanted = (int) config('workspaces.machines.spare_workspaces');

        if ($machines->count() < (int) config('workspaces.machines.max')
            && ($spare < $wanted || $machines->count() < (int) config('workspaces.machines.min'))) {
            // Redis gives a stored number back as a string.
            $pausedUntil = (int) Cache::get(self::PAUSED_UNTIL, 0);

            // A machine that never answers will not answer the next time
            // either; starting another each pass only pays for more.
            if ($pausedUntil > now()->getTimestamp()) {
                return [...$done, sprintf('Not starting machines until %s: the last new machine never answered. Check WORKSPACE_MACHINES_BOX_IMAGE and that machines can reach the control plane.', Carbon::createFromTimestamp($pausedUntil)->format('H:i'))];
            }

            return [...$done, $this->start($name, $cloud)];
        }

        if ($machines->count() > (int) config('workspaces.machines.min') && $spare - $capacity >= $wanted) {
            $empty = $this->longestEmpty($machines, $cloud->billingMinutes());

            if ($empty !== null && $this->retireRunner->handle($empty) === 0) {
                $done[] = "Deleted machine [{$empty->name}]: it held nothing, and the pool has room without it.";
            }
        }

        return $done === [] ? ['The pool is the right size.'] : $done;
    }

    /**
     * Delete machines on the cloud that no runner here belongs to, as when
     * the control plane stopped while it started one. A runner still
     * waiting to hear its machine's id gets it here.
     *
     * @return list<string>
     */
    protected function deleteStrays(string $name, MachineCloud $cloud): array
    {
        $runners = Runner::query()->where('cloud', $name)->get()->keyBy('name');
        $done = [];

        foreach ($cloud->machines() as $id => $runnerName) {
            // PHP turns numeric keys into numbers.
            $id = (string) $id;
            $runner = $runners->get($runnerName);

            if ($runner === null || ($runner->cloud_id !== null && $runner->cloud_id !== $id)) {
                $cloud->delete($id);
                $done[] = "Deleted stray machine [{$id}].";
            } elseif ($runner->cloud_id === null) {
                $runner->update(['cloud_id' => $id]);
            }
        }

        return $done;
    }

    /**
     * Remove machines whose runner never asked for work after they started,
     * or stopped asking long ago, closing whatever they held.
     *
     * @return list<string>
     */
    protected function retireSilent(string $name): array
    {
        $since = now()->subMinutes((int) config('workspaces.machines.boot_minutes'));
        $done = [];

        Runner::query()->where('cloud', $name)
            ->where(fn ($query) => $query
                ->where('last_seen_at', '<', $since)
                ->orWhere(fn ($query) => $query->whereNull('last_seen_at')->where('created_at', '<', $since)))
            ->each(function (Runner $runner) use (&$done) {
                if ($runner->last_seen_at === null) {
                    $this->pauseStarting($runner);
                }

                try {
                    $closed = $this->retireRunner->gone($runner);
                    $done[] = "Deleted machine [{$runner->name}]: its runner stopped answering. Closed {$closed} workspace(s) on it.";
                } catch (Throwable $exception) {
                    report($exception);
                    $done[] = "Could not delete machine [{$runner->name}]: {$exception->getMessage()}";
                }
            });

        return $done;
    }

    /**
     * Stop starting machines for a while, and tell the operator, since a
     * machine whose runner never answered points at a setup problem.
     */
    protected function pauseStarting(Runner $runner): void
    {
        $minutes = (int) config('workspaces.machines.boot_retry_minutes');

        Cache::put(self::PAUSED_UNTIL, now()->addMinutes($minutes)->getTimestamp(), now()->addMinutes($minutes));

        report(new RuntimeException("Machine [{$runner->name}] started, but its runner never asked for work. No machine starts for {$minutes} minutes."));
    }

    /**
     * Delete draining cloud machines once they hold nothing.
     *
     * @return list<string>
     */
    protected function finishDraining(string $name): array
    {
        $done = [];

        Runner::query()->where('cloud', $name)->whereNotNull('draining_at')->each(function (Runner $runner) use (&$done) {
            if ($this->retireRunner->handle($runner) === 0) {
                $done[] = "Deleted machine [{$runner->name}]: it finished draining.";
            }
        });

        return $done;
    }

    /**
     * Count the free places new workspaces can go to, including those on
     * cloud machines still starting.
     */
    protected function spare(int $capacity): int
    {
        $minimumDisk = (int) config('workspaces.boxes.pool.min_free_disk_mb');

        $ready = Runner::query()->online()->whereNull('draining_at')->get()
            ->reject(fn (Runner $runner) => $runner->disk_free_mb !== null && $runner->disk_free_mb < $minimumDisk)
            ->sum(fn (Runner $runner) => max(0, $capacity - $this->pool->load($runner)));

        $starting = Runner::query()->whereNotNull('cloud')->whereNull('last_seen_at')->whereNull('draining_at')->count();

        // Workspaces waiting for room already need the places there are.
        return (int) $ready + $starting * $capacity - $this->pool->waiting();
    }

    /**
     * Start one machine and its runner.
     */
    protected function start(string $name, MachineCloud $cloud): string
    {
        $image = (string) config('workspaces.machines.box_image');

        if ($image === '') {
            return 'Set WORKSPACE_MACHINES_BOX_IMAGE, so new machines know which box image to run.';
        }

        do {
            $runnerName = 'm'.Str::lower(Str::random(9));
        } while (Runner::query()->where('name', $runnerName)->exists());

        $token = Str::random(64);
        $runner = Runner::query()->create(['name' => $runnerName, 'cloud' => $name, 'token_hash' => Runner::hashToken($token)]);

        try {
            $runner->update(['cloud_id' => $cloud->create($runnerName, $this->bootScript->make(
                rtrim((string) (config('workspaces.machines.control_plane_url') ?: config('app.url')), '/'),
                $token,
                $image,
                $cloud->serviceHostCommand(),
            ))]);
        } catch (Throwable $exception) {
            $runner->delete();

            throw $exception;
        }

        return "Started machine [{$runnerName}]: the pool was running out of room.";
    }

    /**
     * Get the cloud machine that has held nothing for longest, once that is
     * longer than "empty_minutes" and its paid time is nearly over.
     *
     * @param  Collection<int, Runner>  $machines
     */
    protected function longestEmpty(Collection $machines, ?int $billingMinutes): ?Runner
    {
        $before = now()->subMinutes((int) config('workspaces.machines.empty_minutes'));

        return $machines
            ->filter(fn (Runner $runner) => $runner->last_seen_at !== null && $this->pool->load($runner) === 0)
            ->filter(fn (Runner $runner) => $this->paidTimeNearlyOver($runner, $billingMinutes))
            ->map(fn (Runner $runner) => ['runner' => $runner, 'since' => $this->emptySince($runner)])
            ->filter(fn (array $candidate) => $candidate['since']->lt($before))
            ->sortBy(fn (array $candidate) => $candidate['since']->getTimestamp())
            ->first()['runner'] ?? null;
    }

    /**
     * Tell whether a machine is in the last minutes of the time already paid
     * for it. Deleting it earlier saves nothing, and a workspace that comes
     * in the meantime can use it instead of a new machine.
     */
    protected function paidTimeNearlyOver(Runner $runner, ?int $billingMinutes): bool
    {
        if ($billingMinutes === null) {
            return true;
        }

        $intoPeriod = (int) $runner->created_at->diffInMinutes(now()) % $billingMinutes;

        return $intoPeriod >= $billingMinutes - min(10, intdiv($billingMinutes, 2));
    }

    /**
     * When a machine last held a workspace, or else when it started.
     */
    protected function emptySince(Runner $runner): Carbon
    {
        $last = Workspace::query()
            ->where('driver', 'runner')
            ->where('driver_id', 'like', $runner->name.PoolProvider::SEPARATOR.'%')
            ->max('updated_at');

        return Carbon::parse(max((string) $last, (string) $runner->created_at));
    }
}
