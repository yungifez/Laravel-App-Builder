<?php

namespace App\Listeners;

use App\Actions\Features\AmendChangeFromDesign;
use App\Enums\FeatureRequestStatus;
use App\Enums\PreviewStatus;
use App\Events\ProjectCommitted;
use App\Jobs\RebuildPreview;
use App\Projects\ProjectRepository;
use App\VisualEditing\DesignDrafts;

class FollowDesignedChange
{
    public function __construct(
        private ProjectRepository $repository,
        private AmendChangeFromDesign $amendChangeFromDesign,
        private DesignDrafts $designDrafts,
    ) {}

    /**
     * A commit on a waiting change's design branch is a design edit (or its
     * undo) on the change: the change's code takes it in, so keeping the
     * change keeps the edit, and its copy catches up as the app's does.
     * The app's design draft has no copy: the app's own preview shows it.
     */
    public function handle(ProjectCommitted $event): void
    {
        $changes = $event->project->featureRequests()
            ->where('status', FeatureRequestStatus::Generated)
            ->whereNotNull('design_base')
            ->get();

        foreach ($changes as $change) {
            if (! $this->repository->hasBranch($event->project, $change->designBranch())
                || $this->repository->head($event->project, $change->designBranch()) !== $event->sha) {
                continue;
            }

            $this->amendChangeFromDesign->handle($change);

            if (DesignDrafts::isDraft($change)) {
                $this->designDrafts->rebuildAppPreview($event->project);

                continue;
            }

            $preview = $change->previews()->where('editable', true)->latest('id')->first();

            if ($preview?->status === PreviewStatus::Ready) {
                RebuildPreview::dispatch($preview)->afterCommit();
            }
        }
    }
}
