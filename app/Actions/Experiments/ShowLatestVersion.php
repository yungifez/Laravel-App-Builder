<?php

namespace App\Actions\Experiments;

use App\Enums\PreviewStatus;
use App\Jobs\RebuildPreview;
use App\Models\Project;

class ShowLatestVersion
{
    /**
     * Bring the project's running app up to the branch the owner now works
     * on, after they move between the main app and an idea. The rebuild
     * copies the files that differ, whichever way the owner moved.
     */
    public function handle(Project $project): void
    {
        $preview = $project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();

        if ($preview?->status === PreviewStatus::Ready) {
            RebuildPreview::dispatch($preview)->afterCommit();
        }
    }
}
