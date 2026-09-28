<?php

namespace App\Actions\Previews;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class RunPreviewScheduledTask
{
    public function __construct(private ReadPreviewLog $readPreviewLog, private ReadPreviewSchedule $readPreviewSchedule, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Run one of the tasks the app on show runs on its own, now, so the
     * owner sees what it does without waiting for its time.
     *
     * @throws ValidationException
     */
    public function handle(Project $project, string $task): void
    {
        $preview = $this->readPreviewLog->preview($project)
            ?? throw ValidationException::withMessages(['task' => __('Your app is not running. Start it and try again.')]);

        if (! collect($this->readPreviewSchedule->handle($project) ?? [])->contains('name', $task)) {
            throw ValidationException::withMessages(['task' => __('This task is no longer in your app.')]);
        }

        $output = $this->runPreviewCommand->handle(
            $preview,
            ['php', 'artisan', 'schedule:test', '--name='.$task, '--no-interaction'],
            300,
            __('The task stopped with a problem. See Problems for what went wrong.'),
        );

        // Two tasks without names cannot be told apart.
        if (str_contains($output, 'No matching scheduled command found')) {
            throw ValidationException::withMessages(['task' => __('Give this task a name in the app to run it from here.')]);
        }

        // What it did shows at once.
        Cache::forget("previews:{$preview->id}:log");
        ReadPreviewData::forget($preview);
    }
}
