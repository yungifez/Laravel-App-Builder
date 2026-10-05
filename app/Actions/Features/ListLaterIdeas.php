<?php

namespace App\Actions\Features;

use App\Models\FeatureRequest;
use App\Models\Project;

class ListLaterIdeas
{
    /**
     * Get what the owner might add later: the follow-ups offered with their
     * latest kept changes, newest first, without repeats (architecture
     * §28.4, "Your business").
     *
     * @return list<string>
     */
    public function handle(Project $project, int $limit = 5): array
    {
        return array_values($project->featureRequests()
            ->whereNotNull('accepted_at')
            ->whereNull('reverted_at')
            ->with('latestRun')
            ->latest('accepted_at')
            ->limit(10)
            ->get()
            // A change kept without a plan offered no follow-ups.
            ->filter(fn (FeatureRequest $featureRequest) => $featureRequest->latestRun?->plan !== null)
            ->flatMap(fn (FeatureRequest $featureRequest) => $featureRequest->latestRun->plan['next'])
            ->unique()
            ->take($limit)
            ->all());
    }
}
