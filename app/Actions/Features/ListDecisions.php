<?php

namespace App\Actions\Features;

use App\Models\FeatureRequest;
use App\Models\Project;

class ListDecisions
{
    /**
     * Words that mark a choice about how the code is built rather than how
     * the app behaves. Such choices are for developers, not the owner.
     */
    protected const BUILD_WORDS = '/`|\b(migrations?|database|columns?|tables?|props?|schema|index(es)?|quer(y|ies)|cache[ds]?|api|endpoints?|routes?|controllers?|models?|components?|types?|fields?|validation|seeders?|factor(y|ies)|tests?|null|strings?|booleans?|integers?|json|arrays?|enums?|css|html|vue|php)\b/i';

    /**
     * List the product decisions behind the changes the owner kept, newest
     * change first: what the owner answered when asked, and what I decided
     * for them and they kept. The durable record is the decision, not the
     * conversation it came from (direction 18 §2).
     *
     * @return list<array{change: int, summary: string, at: string|null, question: string|null, decision: string, by: 'owner'|'builder'}>
     */
    public function handle(Project $project, int $limit = 12): array
    {
        $decisions = [];

        $kept = $project->featureRequests()
            ->whereNotNull('accepted_at')
            ->whereNull('reverted_at')
            ->latest('accepted_at')
            ->with('latestRun')
            ->limit(30)
            ->get();

        foreach ($kept as $featureRequest) {
            foreach ($this->decisionsOf($featureRequest) as $decision) {
                $decisions[] = $decision;

                if (count($decisions) === $limit) {
                    return $decisions;
                }
            }
        }

        return $decisions;
    }

    /**
     * Get one kept change's decisions: the owner's answers first, then the
     * ones I made about how the app behaves.
     *
     * @return list<array{change: int, summary: string, at: string|null, question: string|null, decision: string, by: 'owner'|'builder'}>
     */
    protected function decisionsOf(FeatureRequest $featureRequest): array
    {
        $run = $featureRequest->latestRun;
        $change = [
            'change' => $featureRequest->id,
            'summary' => $featureRequest->summary ?? $featureRequest->prompt,
            'at' => $featureRequest->accepted_at?->toIso8601String(),
        ];
        $decisions = [];

        foreach ($run->answers ?? [] as $answer) {
            $decisions[] = [...$change, 'question' => $answer['question'], 'decision' => $answer['answer'], 'by' => $answer['decided_by'] === 'owner' ? 'owner' : 'builder'];
        }

        foreach ($run->plan['assumptions'] ?? [] as $assumption) {
            if (preg_match(self::BUILD_WORDS, $assumption) !== 1) {
                $decisions[] = [...$change, 'question' => null, 'decision' => $assumption, 'by' => 'builder'];
            }
        }

        return $decisions;
    }
}
