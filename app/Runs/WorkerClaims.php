<?php

namespace App\Runs;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\RunEvent;
use Illuminate\Support\Str;

/**
 * Which change a tool connected to the whole app is writing. Such a token
 * opens whichever change waits next, so a tool still busy with a change
 * the owner stopped, or a second loop on the same change, would otherwise
 * hand its work in to the wrong one. Each get_task gives a fresh code; the
 * newest code on a change is the only one that may write it.
 */
class WorkerClaims
{
    /**
     * Give the change to the tool asking now, ending any earlier claim.
     */
    public function claim(Run $run): string
    {
        $code = Str::lower(Str::random(10));
        $run->recordEvent('worker_claimed', ['claim' => $code]);

        return $code;
    }

    /**
     * The change the tool names with its code, or why it must stop.
     *
     * @return array{0: Run|null, 1: string|null}
     */
    public function named(WorkerTask $task, mixed $code): array
    {
        // A token for one change names that change itself.
        if (! $task->wholeApp) {
            return [$task->run?->refresh(), null];
        }

        if (! is_string($code) || $code === '') {
            // With nothing waiting there is nothing to name yet.
            return $task->run === null ? [null, null] : [null, (string) __('Pass the task code that get_task gave you as `task`. Call get_task first if you have none.')];
        }

        $claimed = RunEvent::query()
            ->where('type', 'worker_claimed')
            ->where('data->claim', $code)
            ->whereHas('run.featureRequest', fn ($query) => $query->whereBelongsTo($task->project))
            ->first();

        if ($claimed === null) {
            return [null, (string) __('That task code is not known here. Call get_task for the change that waits.')];
        }

        $run = $claimed->run->refresh();
        $latest = $run->events()->where('type', 'worker_claimed')->reorder('sequence', 'desc')->first();

        return match (true) {
            in_array($run->status, [RunStatus::Cancelling, RunStatus::Cancelled], true) => [null, (string) __('The owner stopped this change. Stop working on it now and hand nothing back. Do not call get_task again in this session.')],
            $latest?->is($claimed) !== true => [null, (string) __('Another session of your tool took this change over. Stop working on it now and hand nothing back. Do not call get_task again in this session.')],
            default => [$run, null],
        };
    }
}
