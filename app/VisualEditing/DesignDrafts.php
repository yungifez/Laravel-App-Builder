<?php

namespace App\VisualEditing;

use App\Enums\FeatureRequestStatus;
use App\Enums\PreviewStatus;
use App\Enums\VerificationStatus;
use App\Jobs\RebuildPreview;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;

/**
 * Design edits on the app wait in a draft before they join it. The draft is
 * a change of its own, on a branch of its own: the owner's edits land
 * there, the app's preview shows it, and only checked edits join the app
 * when the owner keeps them. Edits on a change's copy need no draft; they
 * wait with the change.
 */
class DesignDrafts
{
    /**
     * What a draft's request is made by, in place of a generator.
     */
    public const GENERATOR = 'design';

    public function __construct(private ProjectRepository $repository) {}

    /**
     * Get the draft that waits on the branch the owner works on.
     */
    public function find(Project $project): ?FeatureRequest
    {
        return $project->featureRequests()
            ->where('generator', self::GENERATOR)
            ->where('experiment_id', $project->experiment_id)
            ->where('status', FeatureRequestStatus::Generated)
            ->whereNull('accepted_at')
            ->whereNull('dismissed_at')
            ->latest('id')
            ->first();
    }

    /**
     * Determine whether the request is a draft of design edits.
     */
    public static function isDraft(?FeatureRequest $featureRequest): bool
    {
        return $featureRequest?->generator === self::GENERATOR;
    }

    /**
     * Make sure an edit on the preview has a branch to land on: a draft for
     * the app's own preview, started from the app as it is now.
     *
     * @throws ValidationException while the draft is being checked.
     */
    public function open(Preview $preview, User $owner): void
    {
        if ($preview->feature_request_id !== null || ! $preview->editable) {
            return;
        }

        $project = $preview->project;
        $draft = $this->find($project);

        if ($draft !== null) {
            $this->refuseWhileChecking($draft);

            return;
        }

        $head = $this->repository->head($project);
        $draft = $project->featureRequests()->create([
            'experiment_id' => $project->experiment_id,
            'user_id' => $owner->id,
            'prompt' => __('Design edits'),
            'generator' => self::GENERATOR,
            'status' => FeatureRequestStatus::Generated,
            'patch' => '',
            'base_revision' => $head,
            'design_base' => $head,
        ]);

        $this->repository->createBranch($project, $draft->designBranch(), $head);
    }

    /**
     * Refuse an edit while the owner's edits are being checked, so what is
     * kept is what was checked.
     *
     * @throws ValidationException
     */
    public function refuseWhileChecking(?FeatureRequest $featureRequest): void
    {
        if ($this->checking($featureRequest)) {
            throw ValidationException::withMessages(['edit' => __('Your edits are being checked. You can change more when that is done.')]);
        }
    }

    /**
     * Determine whether the draft's edits are being checked.
     */
    public function checking(?FeatureRequest $featureRequest): bool
    {
        return self::isDraft($featureRequest)
            && $featureRequest?->verifications()->whereIn('status', [VerificationStatus::Queued, VerificationStatus::Running])->exists() === true;
    }

    /**
     * Move the draft onto the app as it is now, so it holds what the app
     * gained since it started. The draft stays where it was when its edits
     * no longer fit.
     *
     * @throws RepositoryConflict when the edits no longer fit the app.
     */
    public function catchUp(FeatureRequest $draft): void
    {
        $project = $draft->project;
        $head = $this->repository->head($project);

        if ($draft->base_revision === $head) {
            return;
        }

        $branch = $draft->designBranch();
        $next = "{$branch}-next";

        if ($this->repository->hasBranch($project, $next)) {
            $this->repository->deleteBranch($project, $next, $project->branch());
        }

        // Tried on a branch of its own first, so a misfit leaves the draft
        // as it was.
        $this->repository->createBranch($project, $next, $head);

        try {
            $tip = trim((string) $draft->patch) === ''
                ? $head
                : $this->repository->commitPatches($project, $head, [(string) $draft->patch], 'Apply the design edits', null, $next);
        } catch (RepositoryConflict $exception) {
            $this->repository->deleteBranch($project, $next, $project->branch());

            throw $exception;
        }

        $this->repository->deleteBranch($project, $branch, $project->branch());
        $this->repository->createBranch($project, $branch, $tip);
        $this->repository->deleteBranch($project, $next, $project->branch());

        $draft->update(['base_revision' => $head, 'design_base' => $head]);
        $this->rebuildAppPreview($project);
    }

    /**
     * Bring the app's running preview up to what it shows now.
     */
    public function rebuildAppPreview(Project $project): void
    {
        $preview = $project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();

        if ($preview?->status === PreviewStatus::Ready) {
            RebuildPreview::dispatch($preview)->afterCommit();
        }
    }
}
