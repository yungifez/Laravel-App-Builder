<?php

namespace App\Actions\Previews;

use App\Models\Project;
use Illuminate\Validation\ValidationException;

class StartPreviewDataAgain
{
    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Clear the data the app on show has saved and build its tables again,
     * filled by the app's own example data or left empty. Only the copy the
     * owner tries is changed, never the app online.
     *
     * @throws ValidationException
     */
    public function handle(Project $project, bool $examples): void
    {
        $preview = $this->readPreviewLog->preview($project)
            ?? throw ValidationException::withMessages(['app' => __('Your app is not running. Start it and try again.')]);

        $this->runPreviewCommand->handle(
            $preview,
            ['php', 'artisan', 'migrate:fresh', '--force', '--no-interaction', ...($examples ? ['--seed'] : [])],
            180,
            $examples
                ? __('Your app could not be filled with examples. Ask me to fix its example data.')
                : __('Your app could not be emptied. Ask me to look at how it saves data.'),
        );

        ReadPreviewData::forget($preview);
    }
}
