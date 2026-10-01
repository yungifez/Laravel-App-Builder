<?php

namespace App\Actions\Previews;

use App\Actions\Features\RequestFeature;
use App\Actions\Features\RequestFollowUp;
use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Turn a problem the owner ran into while trying their app into an ask to
 * fix it, with one click. The owner's words stay plain; the builder gets the
 * error and where it happened. A problem in a change the owner tries is
 * fixed in that change, as a follow-up.
 */
class RequestPreviewProblemFix
{
    public function __construct(
        private ReadPreviewProblems $readProblems,
        private ReadPreviewLog $readLog,
        private RequestFeature $requestFeature,
        private RequestFollowUp $requestFollowUp,
    ) {}

    /**
     * Ask for the problem to be fixed, or get the fix already asked for, so
     * a second click does not pay for the same work twice.
     *
     * @throws ValidationException when the app on show no longer lists it.
     */
    public function handle(Project $project, User $requester, string $problemId): FeatureRequest
    {
        $preview = $this->readLog->preview($project);
        $change = $preview?->featureRequest;

        $asked = $project->featureRequests()
            ->where('live_errors->problem', $problemId)
            ->where('parent_id', $change?->id)
            // A kept fix that did not hold is not the answer: ask again.
            ->whereNull('accepted_at')
            ->whereNull('dismissed_at')
            ->whereNotIn('status', [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled])
            ->latest('id')
            ->first();

        if ($asked !== null) {
            return $asked;
        }

        // The problem is read again from the app, not taken from the page.
        $problem = collect($this->readProblems->handle($project))->firstWhere('id', $problemId);

        if ($problem === null || $preview === null) {
            throw ValidationException::withMessages(['fix' => __('This problem is no longer in your app. Try again if it comes back.')]);
        }

        $liveErrors = [
            'preview_id' => $preview->id,
            'problem' => $problemId,
            'errors' => [[
                'class' => $problem['class'],
                'message' => $problem['message'],
                'count' => $problem['count'],
                'place' => $problem['place'],
                'trace' => $problem['trace'],
            ]],
        ];

        return $change === null
            ? $this->requestFeature->handle($project, $requester, __('Fix this problem I ran into while trying my app: :words', ['words' => $problem['words']]), liveErrors: $liveErrors)
            : $this->requestFollowUp->handle($change, $requester, __('Fix this problem I ran into while trying this change: :words', ['words' => $problem['words']]), liveErrors: $liveErrors);
    }
}
