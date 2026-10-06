<?php

namespace App\Actions\Projects;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\Project;
use App\Models\Run;
use App\Runs\ConstructionDriverManager;
use App\Runs\Drivers\WorkerDriver;
use Illuminate\Support\Facades\DB;

class DisconnectOwnTool
{
    /**
     * The open runs a change can wait in for the owner's tool.
     */
    protected const WAITING = [RunStatus::Queued, RunStatus::Planning, RunStatus::Implementing, RunStatus::NeedsUserDecision];

    public function __construct(
        private ConstructionDriverManager $drivers,
        private WorkerDriver $workers,
    ) {}

    /**
     * Close the owner's tool's connection. New changes are written by us
     * again, and so are the changes that wait for their tool: each keeps
     * its plan and the owner's answers, and our coder picks it up where it
     * is. A change their tool already handed back is left to finish.
     *
     * @return int How many changes came back to us
     */
    public function handle(Project $project): int
    {
        $project->tokens()->delete();

        $handedBack = 0;

        Run::query()
            ->where('driver', 'worker')
            ->whereIn('status', self::WAITING)
            ->whereHas('featureRequest', fn ($query) => $query->whereBelongsTo($project))
            ->pluck('id')
            ->each(function (int $id) use (&$handedBack) {
                $run = $this->handBack($id);

                if ($run === null) {
                    return;
                }

                $handedBack++;

                // A run waiting for a patch has nothing carrying it forward;
                // the others are carried by their job, or wait for the owner.
                if ($run->status === RunStatus::Implementing) {
                    ExecuteRun::dispatch($run);
                }
            });

        return $handedBack;
    }

    /**
     * Switch one run to our coder, unless the owner's tool handed its
     * change back first. The tool hands back under the same lock, so only
     * one of the two wins.
     */
    protected function handBack(int $id): ?Run
    {
        return DB::transaction(function () use ($id) {
            $run = Run::query()->lockForUpdate()->find($id);

            // Only a run still building can have a change handed in that is
            // being applied; one stopped for the owner switches as it is.
            if ($run === null || $run->driver !== 'worker' || ! in_array($run->status, self::WAITING, true)
                || ($run->status === RunStatus::Implementing && $this->workers->submission($run) !== null)) {
                return null;
            }

            $driver = $this->drivers->getDefaultDriver();
            $run->update(['driver' => $driver]);
            $run->recordEvent('handed_back', ['driver' => $driver]);
            $run->tokens()->delete();

            return $run;
        });
    }
}
