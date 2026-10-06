<?php

namespace App\Actions\Projects;

use App\Actions\Features\RetryFeatureRequest;
use App\Enums\ChangeState;
use App\Enums\FeatureRequestStatus;
use App\Enums\NextStep;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Features\NewTests;
use App\Features\PatchSummary;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Support\Collection;

class SummarizeChanges
{
    /**
     * List the owner's asks, newest first. Each ask is a top-level request
     * together with its follow-ups, and it opens on the request the owner
     * should look at next.
     *
     * The newest request decides while it is being made or waits for the
     * owner. Otherwise a kept request wins, so a kept follow-up does not
     * leave its parent "waiting" for ever.
     *
     * @return list<array{id: string, prompt: string, background: bool, summary: string|null, state: string, stopped_by_owner: bool, asks: bool, question: string|null, proved: int, passing: int, dismissable: bool, updated_at: string|null}>
     */
    public function handle(Project $project): array
    {
        $requests = $project->featureRequests()->inLine($project)->latest('id')->get();
        $triedAgain = $project->featureRequests()->whereNotNull('retry_of_id')->pluck('retry_of_id')->flip();

        return array_values($requests->whereNull('parent_id')
            // A tidy-up set aside in the background never reached the app,
            // so there is nothing to tell the owner.
            ->reject(fn (FeatureRequest $root) => $root->tidy !== null && $root->dismissed_at !== null)
            ->map(function (FeatureRequest $root) use ($requests, $triedAgain) {
                $thread = $this->thread($root, $requests)->sortByDesc('id')->values();
                [$state, $shown] = $this->state($thread);

                // The owner tried it again: the newer try stands for it,
                // so one ask is not listed once for every try.
                if ($triedAgain->has($shown->id)) {
                    return null;
                }
                $toTry = $state === ChangeState::Waiting && $shown->status !== FeatureRequestStatus::Generating;
                [$proved, $passing] = $toTry ? $this->proof($shown) : [0, 0];

                // Set aside by the owner; a kept change is in the app and
                // stays kept.
                if ($root->dismissed_at !== null && $state !== ChangeState::Kept) {
                    $state = ChangeState::Dismissed;
                }

                // A tidy-up is kept on its own, so until then the builder is
                // still at it and the owner has nothing to look at.
                if ($root->tidy !== null && $state === ChangeState::Waiting) {
                    $state = ChangeState::Working;
                }

                return [
                    'id' => $shown->uuid,
                    'prompt' => $root->background() ?? $root->prompt,
                    // Made by the builder on its own, not asked for.
                    'background' => $root->tidy !== null,
                    'summary' => $shown->summary,
                    'state' => $state->value,
                    // The owner stopped it themselves: nothing went wrong.
                    'stopped_by_owner' => $state === ChangeState::Stopped && ($shown->status === FeatureRequestStatus::Cancelled || $shown->latestRun?->status === RunStatus::Cancelled),
                    // Waiting on an answer rather than on a look at the result.
                    'asks' => $asks = $state === ChangeState::Waiting && ($shown->status === FeatureRequestStatus::Generating || $shown->latestRun?->stop_reason?->nextStep() === NextStep::Answer),
                    // What the owner is asked, so the list says what to do.
                    'question' => $asks ? ($shown->latestRun?->question['text'] ?? null) : null,
                    // A change to try says how many of its tests fail
                    // without it, so the list shows it was proved, not only made.
                    'proved' => $proved,
                    // Otherwise, how many tests it added that pass with every
                    // other check: checked, though not proved.
                    'passing' => $passing,
                    // The ask can be marked as not needed: nothing of it is kept.
                    'dismissable' => $state !== ChangeState::Kept,
                    'updated_at' => ($shown->reverted_at ?? $shown->accepted_at ?? $shown->updated_at)?->toIso8601String(),
                ];
            })
            ->filter()
            ->all());
    }

    /**
     * Count the asks that wait for the owner to look at them.
     */
    public function waiting(Project $project): int
    {
        return collect($this->handle($project))
            ->where('state', ChangeState::Waiting->value)
            ->count();
    }

    /**
     * What the app's card says about its changes: how many wait for the
     * owner, and whether the newest ask is being made or stopped. A stopped
     * one the owner set aside, or stopped themselves, does not count.
     *
     * @return array{waiting: int, now: 'working'|'stopped'|null}
     */
    public function card(Project $project): array
    {
        $changes = collect($this->handle($project));
        $newest = $changes->first(fn (array $change) => $change['state'] !== ChangeState::Dismissed->value);

        return [
            'waiting' => $changes->where('state', ChangeState::Waiting->value)->count(),
            'now' => match ($newest['state'] ?? null) {
                ChangeState::Working->value => 'working',
                ChangeState::Stopped->value => $newest['stopped_by_owner'] ? null : 'stopped',
                default => null,
            },
        ];
    }

    /**
     * Count the tests a change added that fail without it and pass with it.
     * When none was seen to fail without it, count the tests it added that
     * pass, once every check passed.
     *
     * @return array{int, int}
     */
    protected function proof(FeatureRequest $request): array
    {
        $verification = $request->verifications()->latest('id')->first();
        $proved = count(NewTests::ending($verification?->evidence['new_tests'] ?? [], NewTests::FAILED, $request->patch));
        $checked = in_array($verification?->status, [VerificationStatus::Passed, VerificationStatus::Unverified], true);

        return [$proved, $proved === 0 && $checked ? count(PatchSummary::addedTests($request->patch)) : 0];
    }

    /**
     * Gather a request and all its follow-ups, however deep.
     *
     * @param  Collection<int, FeatureRequest>  $requests  Every request of the project
     * @return Collection<int, FeatureRequest>
     */
    protected function thread(FeatureRequest $request, Collection $requests): Collection
    {
        return collect([$request])->merge(
            $requests->where('parent_id', $request->id)
                ->flatMap(fn (FeatureRequest $child) => $this->thread($child, $requests)),
        );
    }

    /**
     * @param  Collection<int, FeatureRequest>  $thread  Newest first
     * @return array{ChangeState, FeatureRequest}
     */
    protected function state(Collection $thread): array
    {
        /** @var FeatureRequest $newest */
        $newest = $thread->first();

        if ($newest->status === FeatureRequestStatus::Generating) {
            $run = $newest->latestRun;

            // A proposal from the checks waits on the owner just as a question does.
            if ($run?->question !== null || $run?->stop_reason?->nextStep() === NextStep::Answer) {
                return [ChangeState::Waiting, $newest];
            }

            // A run that stopped without a question could not finish: the
            // owner tries it again, so it is not still being worked on.
            return [in_array($run?->status, [RunStatus::NeedsUserDecision, RunStatus::Failed], true) ? ChangeState::Stopped : ChangeState::Working, $newest];
        }

        if ($newest->status === FeatureRequestStatus::Answered) {
            return [ChangeState::Answered, $newest];
        }

        // Made, but the run stopped before it was checked: the owner asks again.
        if (RetryFeatureRequest::stoppedWhileChecking($newest)) {
            return [ChangeState::Stopped, $newest];
        }

        if ($newest->status === FeatureRequestStatus::Generated && $newest->commit_sha === null && $newest->reverted_at === null) {
            return [ChangeState::Waiting, $newest];
        }

        $kept = $thread->first(fn (FeatureRequest $request) => $request->isAccepted());

        if ($kept !== null) {
            return [ChangeState::Kept, $kept];
        }

        // A follow-up that failed leaves the change it built on as it was:
        // that one still waits to be tried, rather than the whole ask
        // reading as stopped.
        $waiting = $thread->first(fn (FeatureRequest $request) => $request->status === FeatureRequestStatus::Generated && $request->commit_sha === null && $request->reverted_at === null);

        if ($newest->status === FeatureRequestStatus::Failed && $waiting !== null) {
            return [ChangeState::Waiting, $waiting];
        }

        $undone = $thread->first(fn (FeatureRequest $request) => $request->reverted_at !== null);

        return $undone !== null ? [ChangeState::Undone, $undone] : [ChangeState::Stopped, $newest];
    }
}
