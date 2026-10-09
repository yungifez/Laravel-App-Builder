<?php

namespace App\Actions\Previews;

use App\Jobs\MovePreviewClock;
use App\Models\Project;
use App\Previews\PreviewClock;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

class SetPreviewClock
{
    public function __construct(private ReadPreviewLog $readPreviewLog, private WorkspaceManager $workspaces) {}

    /**
     * Move the app on show a day, a week or a month ahead, or back to
     * today. Moving ahead runs what the app's schedule would have run, so
     * it goes on in the background; back to today takes at once. What
     * happened in the skipped time stays.
     *
     * @throws ValidationException
     */
    public function handle(Project $project, string $jump): void
    {
        $preview = $this->readPreviewLog->preview($project)
            ?? throw ValidationException::withMessages(['jump' => __('Your app is not running. Start it and try again.')]);
        $workspace = $preview->workspace;

        if ($workspace === null || ! Config::boolean('builder.preview.recorder.enabled') || ! Config::boolean('builder.preview.clock.enabled')) {
            throw ValidationException::withMessages(['jump' => __('This is our fault: your app on show cannot move in time now. Start it again and try once more.')]);
        }

        if ($jump === 'today') {
            $directory = trim(Config::string('builder.preview.recorder.directory'), '/');
            $this->workspaces->driver($workspace->driver)->writeFile((string) $workspace->driver_id, "{$directory}/clock.json", PreviewClock::file(0));
            Cache::forget(ReadPreviewClock::outcomeKey($preview));
            Cache::forget("previews:{$preview->id}:schedule");

            return;
        }

        if (! Cache::add(ReadPreviewClock::movingKey($preview), true, now()->addMinutes(35))) {
            throw ValidationException::withMessages(['jump' => __('Your app is still moving ahead. Wait for it to finish.')]);
        }

        Cache::forget(ReadPreviewClock::outcomeKey($preview));
        MovePreviewClock::dispatch($preview, $jump);
    }
}
