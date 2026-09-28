<?php

namespace App\Actions\Projects;

use App\Enums\ChangeState;
use App\Enums\FeatureRequestStatus;
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
     * @return list<array{id: string, prompt: string, background: bool, summary: string|null, state: string, asks: bool, question: string|null, dismissable: bool, updated_at: string|null}>
     */
    public function handle(Project $project): array
    {
        $requests = $project->featureRequests()->inLine($project)->latest('id')->get();

        return array_values($requests->whereNull('parent_id')
            // A tidy-up set aside in the background never reached the app,
            // so there is nothing to tell the owner.
            ->reject(fn (FeatureRequest $root) => $root->tidy !== null && $root->dismissed_at !== null)
            ->map(function (FeatureRequest $root) use ($requests) {
                $thread = $this->thread($root, $requests)->sortByDesc('id')->values();
                [$state, $shown] = $this->state($thread);

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
                    // Waiting on an answer rather than on a look at the result.
                    'asks' => $asks = $state === ChangeState::Waiting && $shown->status === FeatureRequestStatus::Generating,
                    // What the owner is asked, so the list says what to do.
                    'question' => $asks ? ($shown->latestRun?->question['text'] ?? null) : null,
                    // The ask can be marked as not needed: nothing of it is kept.
                    'dismissable' => $state !== ChangeState::Kept,
                    'updated_at' => ($shown->reverted_at ?? $shown->accepted_at ?? $shown->updated_at)?->toIso8601String(),
                ];
            })
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
            return [$newest->latestRun?->question !== null ? ChangeState::Waiting : ChangeState::Working, $newest];
        }

        if ($newest->status === FeatureRequestStatus::Answered) {
            return [ChangeState::Answered, $newest];
        }

        if ($newest->status === FeatureRequestStatus::Generated && $newest->commit_sha === null && $newest->reverted_at === null) {
            return [ChangeState::Waiting, $newest];
        }

        $kept = $thread->first(fn (FeatureRequest $request) => $request->isAccepted());

        if ($kept !== null) {
            return [ChangeState::Kept, $kept];
        }

        $undone = $thread->first(fn (FeatureRequest $request) => $request->reverted_at !== null);

        return $undone !== null ? [ChangeState::Undone, $undone] : [ChangeState::Stopped, $newest];
    }
}
