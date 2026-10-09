<?php

namespace App\Actions\Previews;

use App\Models\Project;
use App\Previews\ScheduledTasks;
use Illuminate\Support\Facades\Cache;

class ReadPreviewSchedule
{
    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Get the tasks the app on show runs on its own, or null while it does
     * not run. They are read through the app itself.
     *
     * @return list<array{name: string, words: string, when: string, next: string|null, expression: string, command: string, timezone: string, repeat: int|null}>|null
     */
    public function handle(Project $project): ?array
    {
        $preview = $this->readPreviewLog->preview($project);

        if ($preview === null) {
            return null;
        }

        // What runs changes only with the app's code, but the next time moves.
        return Cache::remember("previews:{$preview->id}:schedule", now()->addSeconds(20), fn () => ScheduledTasks::in((string) rescue(
            fn () => $this->runPreviewCommand->handle($preview, ['php', 'artisan', 'schedule:list', '--json', '--no-interaction'], 60, ''),
            '',
            report: false,
        )));
    }
}
