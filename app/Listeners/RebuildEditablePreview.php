<?php

namespace App\Listeners;

use App\Enums\PreviewStatus;
use App\Events\ProjectCommitted;
use App\Jobs\RebuildPreview;

class RebuildEditablePreview
{
    /**
     * Bring the project's running editable preview up to the new commit, so
     * the owner never edits a version that is already out of date. The
     * rebuild copies the changed files from the branch, so one rebuild
     * catches up with several commits.
     */
    public function handle(ProjectCommitted $event): void
    {
        $preview = $event->project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();

        if ($preview?->status === PreviewStatus::Ready) {
            RebuildPreview::dispatch($preview)->afterCommit();
        }
    }
}
