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
     * @return list<array{id: int, prompt: string, summary: string|null, state: string, updated_at: string|null}>
     */
    public function handle(Project $project): array
    {
        $requests = $project->featureRequests()->latest('id')->get();

        return array_values($requests->whereNull('parent_id')
            ->map(function (FeatureRequest $root) use ($requests) {
                $thread = $this->thread($root, $requests)->sortByDesc('id')->values();
                [$state, $shown] = $this->state($thread);

                return [
                    'id' => $shown->id,
                    'prompt' => $root->prompt,
                    'summary' => $shown->summary,
                    'state' => $state->value,
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
            return [ChangeState::Working, $newest];
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
