<?php

namespace App\Actions\Previews;

use App\Actions\Features\OpenChangeForDesign;
use App\Enums\PreviewStatus;
use App\Jobs\RebuildPreview;
use App\Jobs\StartPreview;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A first version's preview starts while the coder works, on the app as it
 * was, so the change only has to be handed to the build already running
 * when it lands. The owner does not see it until then.
 */
class WarmChangePreview
{
    public function __construct(
        private MakeRoomForPreview $makeRoomForPreview,
        private StopPreview $stopPreview,
        private ProjectRepository $repository,
        private OpenChangeForDesign $openChangeForDesign,
    ) {}

    /**
     * Start the preview of a project's first change before the change is
     * made. A later change, or one with no base commit to design on, keeps
     * the usual start once it is built. It never closes a preview the owner
     * is looking at.
     */
    public function start(FeatureRequest $featureRequest): ?Preview
    {
        if (! config('builder.preview.automatic') || ! config('builder.preview.warm')
            || $featureRequest->base_revision === null
            || trim((string) $featureRequest->patch) !== ''
            || $featureRequest->project->featureRequests()->where('id', '<', $featureRequest->id)->exists()
            || $this->running($featureRequest) !== null
            || ! $this->makeRoomForPreview->handle($featureRequest->project, automatic: true)) {
            return null;
        }

        return DB::transaction(function () use ($featureRequest) {
            $preview = $featureRequest->previews()->create([
                'project_id' => $featureRequest->project_id,
                'editable' => true,
                'host' => 'p'.Str::lower(Str::random(31)),
                'status' => PreviewStatus::Starting,
                'expires_at' => now()->addMinutes((int) config('builder.preview.max_minutes')),
            ]);

            StartPreview::dispatch($preview)->afterCommit();

            return $preview;
        });
    }

    /**
     * Put the built change on the branch the warm preview runs from, and
     * hand it to that preview's build. Returns false when there is no warm
     * preview to take it, so the usual preview starts instead.
     */
    public function land(FeatureRequest $featureRequest): bool
    {
        if (trim((string) $featureRequest->patch) === '') {
            return false;
        }

        $project = $featureRequest->project;
        $branch = $featureRequest->designBranch();
        // A branch still at the app as it was gets the change even with no
        // preview left on it, or the next preview would show the app without it.
        $warm = $featureRequest->design_base !== null && $this->repository->hasBranch($project, $branch)
            && $this->repository->head($project, $branch) === $featureRequest->design_base;

        if ($warm) {
            $this->repository->commitPatches($project, (string) $featureRequest->design_base, [(string) $featureRequest->patch], 'Apply the change', null, $branch);
        }

        $preview = $this->running($featureRequest);

        // A branch that already holds a version of the change is the usual path's.
        if ($preview === null || ! $preview->editable || (! $warm && $featureRequest->design_base !== null)) {
            return false;
        }

        // The preview has not opened its branch yet: open it with the change
        // in, and the preview starts on that.
        if ($featureRequest->design_base === null) {
            $this->openChangeForDesign->handle($featureRequest);
        }

        // One still starting catches up once it is ready.
        if ($preview->status === PreviewStatus::Ready) {
            RebuildPreview::dispatch($preview);
        }

        return true;
    }

    /**
     * Let the warm preview go when the run stops with no change for it to
     * show, along with the branch it ran from, so nothing of it is left.
     */
    public function release(FeatureRequest $featureRequest): void
    {
        if (trim((string) $featureRequest->patch) !== '') {
            return;
        }

        $featureRequest->previews()
            ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
            ->each(fn (Preview $preview) => $this->stopPreview->handle($preview));

        $project = $featureRequest->project;

        if ($featureRequest->design_base !== null && $this->repository->hasBranch($project, $featureRequest->designBranch())) {
            $this->repository->deleteBranch($project, $featureRequest->designBranch(), $project->branch());
            $featureRequest->update(['design_base' => null]);
        }
    }

    protected function running(FeatureRequest $featureRequest): ?Preview
    {
        return $featureRequest->previews()->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])->latest('id')->first();
    }
}
