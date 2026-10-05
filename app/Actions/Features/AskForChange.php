<?php

namespace App\Actions\Features;

use App\Actions\Billing\MeasureUsage;
use App\Actions\Operations\SummarizeSpend;
use App\Actions\Projects\SummarizeChanges;
use App\Enums\ChangeState;
use App\Features\SpendPause;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AskForChange
{
    public function __construct(
        private SummarizeChanges $summarizeChanges,
        private RequestFeature $requestFeature,
        private RequestFollowUp $requestFollowUp,
        private SummarizeSpend $summarizeSpend,
        private MeasureUsage $measureUsage,
    ) {}

    /**
     * Ask for a change from the app's message box. While a change waits for
     * the owner to try it, the ask builds on top of the newest one, as the
     * owner expects: each ask starts from the app they last saw. Otherwise
     * it starts from the app as kept.
     *
     * @param  array{file: string, line: int, column: int, tag: string, text: string|null, area: string|null}|null  $selection  The element the owner pointed at
     * @param  list<array{path: string, name: string}>  $images  Pictures the owner attached, already kept
     *
     * @throws ValidationException when new work is paused, or the plan's
     *                             use for the month ran out.
     */
    public function handle(Project $project, User $requester, string $prompt, ?array $selection = null, array $images = []): FeatureRequest
    {
        // An ask made now would only stop the same way, and leave a change
        // behind each time: say so, and what to do, before asking.
        if ($this->summarizeSpend->dailyLimitReached()) {
            throw ValidationException::withMessages(['prompt' => SpendPause::message()]);
        }

        $usage = $this->measureUsage->handle($project->owner);

        if ($usage['reached']) {
            throw ValidationException::withMessages(['prompt' => __('You have used all the AI use your plan includes this month. It starts again on :date, or you can move to a bigger plan in Settings. Nothing in your app changed.', [
                'date' => $usage['resets_at']->isoFormat('D MMMM'),
            ])]);
        }

        $waiting = $this->newestWaiting($project);

        return $waiting !== null
            ? $this->requestFollowUp->handle($waiting, $requester, $prompt, selection: $selection, images: $images)
            : $this->requestFeature->handle($project, $requester, $prompt, $selection, images: $images);
    }

    /**
     * Get the request on show of the newest change the owner can try, if it
     * can be built on. A change that waits on an answer, or that the builder
     * made on its own, is passed over.
     */
    public function newestWaiting(Project $project): ?FeatureRequest
    {
        $change = collect($this->summarizeChanges->handle($project))
            ->first(fn (array $change) => $change['state'] === ChangeState::Waiting->value && ! $change['asks'] && ! $change['background']);

        $request = $change === null ? null : $project->featureRequests()->where('uuid', $change['id'])->first();

        return $request !== null && RequestFollowUp::continuable($request) ? $request : null;
    }
}
