<?php

namespace App\Actions\Developers;

use App\Models\DeveloperReview;
use App\Models\Experiment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DeveloperAsked;
use App\Projects\ProjectRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * The owner asks one of our developers to look at a change or at the whole
 * app. We write what the developer reads now, once, so it stays the same
 * however the app moves on (architecture §29.3).
 */
class AskDeveloper
{
    public function __construct(
        private ProjectRepository $repository,
        private WriteReviewRequest $writeReviewRequest,
    ) {}

    /**
     * Ask about the change, or about the whole app when there is none.
     */
    public function handle(Project $project, User $owner, string $question, ?FeatureRequest $featureRequest = null): DeveloperReview
    {
        // A kept change is read as kept; one not kept yet, on the code it was made on.
        $revision = $featureRequest === null ? null : ($featureRequest->commit_sha ?? $featureRequest->base_revision);
        $revision ??= $this->repository->exists($project) ? ($this->repository->head($project, Experiment::mainBranch()) ?: null) : null;

        $review = $project->developerReviews()->create([
            'user_id' => $owner->id,
            'feature_request_id' => $featureRequest?->id,
            'question' => trim($question),
            'revision' => $revision,
            'bundle' => $this->writeReviewRequest->handle($project, $question, $featureRequest, $revision),
        ]);

        Notification::send($this->developers($owner), new DeveloperAsked($review));

        return $review;
    }

    /**
     * Get our developers who can answer: every operator but the owner who
     * asked.
     *
     * @return Collection<int, User>
     */
    protected function developers(User $owner): Collection
    {
        return User::query()
            ->whereIn(DB::raw('lower(email)'), (array) config('operations.operators'))
            ->whereKeyNot($owner->getKey())
            ->get()
            ->filter(fn (User $user) => Gate::forUser($user)->allows('viewOperations'))
            ->values();
    }
}
