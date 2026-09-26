<?php

namespace App\Listeners;

use App\Enums\PreviewStatus;
use App\Events\ProjectCommitted;
use App\Jobs\RebuildPreview;
use App\Projects\ProjectRepository;

class RebuildEditablePreview
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Bring the project's running editable preview up to the new commit, so
     * the owner never edits a version that is already out of date. The
     * rebuild copies the changed files from the branch, so one rebuild
     * catches up with several commits. A commit to a branch the owner is
     * not working on (another idea, or the main app while in an idea) does
     * not change what they see.
     */
    public function handle(ProjectCommitted $event): void
    {
        if ($this->repository->head($event->project->refresh(), null) !== $event->sha) {
            return;
        }

        $preview = $event->project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();

        if ($preview?->status === PreviewStatus::Ready) {
            RebuildPreview::dispatch($preview)->afterCommit();
        }
    }
}
