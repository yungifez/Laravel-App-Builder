<?php

namespace App\Actions\Previews;

use App\Models\Project;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

class SetPreviewFault
{
    public function __construct(private ReadPreviewLog $readPreviewLog, private WorkspaceManager $workspaces) {}

    /**
     * Make one kind of thing fail in the app on show (its email, its outside
     * calls or its disk), or let all work again with "none". The recorder
     * inside the app reads the choice as each page starts, so it takes at
     * once. The app's next start drops it.
     */
    public function handle(Project $project, string $fault): void
    {
        $preview = $this->readPreviewLog->preview($project)
            ?? throw ValidationException::withMessages(['fault' => __('Your app is not running. Start it and try again.')]);
        $workspace = $preview->workspace;

        if ($workspace === null || ! Config::boolean('builder.preview.recorder.enabled')) {
            throw ValidationException::withMessages(['fault' => __('This is our fault: your app on show cannot pretend this now. Start it again and try once more.')]);
        }

        $directory = trim(Config::string('builder.preview.recorder.directory'), '/');

        $this->workspaces->driver($workspace->driver)->writeFile(
            (string) $workspace->driver_id,
            "{$directory}/fault.json",
            (string) json_encode(['kind' => $fault === 'none' ? null : $fault]),
        );

        Cache::forget("previews:{$preview->id}:trace");
    }
}
