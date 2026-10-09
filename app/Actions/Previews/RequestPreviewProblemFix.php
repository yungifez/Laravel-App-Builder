<?php

namespace App\Actions\Previews;

use App\Actions\Billing\MeasureUsage;
use App\Actions\Context\RecordDecision;
use App\Actions\Features\RequestFeature;
use App\Actions\Features\RequestFollowUp;
use App\Actions\Features\RetryFeatureRequest;
use App\Actions\Operations\SummarizeSpend;
use App\Enums\FeatureRequestStatus;
use App\Features\SpendPause;
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
        private RecordDecision $recordDecision,
        private SummarizeSpend $summarizeSpend,
        private MeasureUsage $measureUsage,
    ) {}

    /**
     * Ask for the problem to be fixed, or get the fix already asked for, so
     * a second click does not pay for the same work twice.
     *
     * @throws ValidationException when the app on show no longer lists it,
     *                             or today's AI spend or the owner's plan is
     *                             used up.
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
            ->get()
            // A fix that stopped is asked for again, not reopened.
            ->first(fn (FeatureRequest $fix) => ! RetryFeatureRequest::retryable($fix));

        if ($asked !== null) {
            return $asked;
        }

        // The problem is read again from the app, not taken from the page.
        $problem = collect($this->readProblems->handle($project))->firstWhere('id', $problemId);

        if ($problem === null || $preview === null) {
            throw ValidationException::withMessages(['fix' => __('This problem is no longer in your app. Try again if it comes back.')]);
        }

        // A fix asked now would only stop the same way, and leave a change
        // behind for each click: say so before asking.
        if ($this->summarizeSpend->dailyLimitReached()) {
            throw ValidationException::withMessages(['fix' => SpendPause::message()]);
        }

        $usage = $this->measureUsage->handle($project->owner);

        if ($usage['reached']) {
            throw ValidationException::withMessages(['fix' => __('You have used all the AI use your plan includes this month. It starts again on :date, or you can move to a bigger plan in Settings. Nothing in your app changed.', [
                'date' => $usage['resets_at']->isoFormat('D MMMM'),
            ])]);
        }

        // Asking the app to cope while something is down answers the
        // owner's question about it, which later changes follow too.
        if ($change === null && $problem['during'] !== null) {
            $this->recordDecision->handle($project, ClearPreviewProblem::question($problem['during']), ClearPreviewProblem::COPE);
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
                'during' => $problem['during'],
            ]],
        ];

        // The owner's words say what they chose, not only what went wrong.
        $words = $problem['during'] !== null
            ? __('Make my app keep working when :what.', ['what' => ClearPreviewProblem::outage($problem['during'])])
            : __('Fix this problem I ran into while trying my app: :words', ['words' => $problem['words']]);

        return $change === null
            ? $this->requestFeature->handle($project, $requester, $words, liveErrors: $liveErrors)
            : $this->requestFollowUp->handle($change, $requester, __('Fix this problem I ran into while trying this change: :words', ['words' => $problem['words']]), liveErrors: $liveErrors);
    }
}
