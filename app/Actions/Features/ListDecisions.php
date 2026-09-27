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
     * List the product decisions behind the owner's app: what the owner
     * answered when asked, and what I decided for them and they kept, newest
     * change first. The durable record is the decision, not the
     * conversation it came from (direction 18 §2).
     *
     * The owner's answers are also written into the notes' decisions
     * section when given. An answer found there is shown once, with the
     * change it came from; answers only the notes hold (the change was not
     * kept, or the owner wrote it there) follow, with no change. A null
     * limit lists them all.
     *
     * @return list<array{change: int|null, summary: string|null, at: string|null, question: string|null, decision: string, by: 'owner'|'builder'}>
     */
    public function handle(Project $project, ?string $recorded = null, ?int $limit = 12): array
    {
        $noted = $this->bullets($recorded);
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
                if ($decision['by'] === 'owner') {
                    $noted = array_values(array_diff($noted, ["{$decision['question']} {$decision['decision']}"]));
                }

                $decisions[] = $decision;
            }
        }

        foreach ($noted as $bullet) {
            // Recorded as "question answer": the question ends at its mark.
            $split = preg_match('/^(.*\?)\s+(\S.*)$/s', $bullet, $parts) === 1;

            $decisions[] = [
                'change' => null,
                'summary' => null,
                'at' => null,
                'question' => $split ? $parts[1] : null,
                'decision' => $split ? $parts[2] : $bullet,
                'by' => 'owner',
            ];
        }

        return $limit === null ? $decisions : array_slice($decisions, 0, $limit);
    }

    /**
     * Get the bullets of a notes section, joining wrapped lines.
     *
     * @return list<string>
     */
    protected function bullets(?string $section): array
    {
        $bullets = [];

        foreach (preg_split('/\R/', trim((string) $section)) ?: [] as $line) {
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $bullet) === 1) {
                $bullets[] = trim($bullet[1]);
            } elseif (trim($line) !== '' && $bullets !== []) {
                $bullets[array_key_last($bullets)] .= ' '.trim($line);
            }
        }

        return $bullets;
    }

    /**
     * Get one kept change's decisions: the owner's answers first, then the
     * ones I made about how the app behaves.
     *
     * @return list<array{change: int|null, summary: string|null, at: string|null, question: string|null, decision: string, by: 'owner'|'builder'}>
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
