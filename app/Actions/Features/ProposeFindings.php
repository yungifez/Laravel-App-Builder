<?php

namespace App\Actions\Features;

use App\Models\FeatureRequest;
use App\Models\FindingProposal;
use App\Models\Run;
use Illuminate\Support\Str;

/**
 * Turn the agent's case for a finding of the gate into a question for the
 * owner (direction 33). The gate's findings reach the agent with a key,
 * such as "B1". Instead of changing the code, the agent may answer
 * "KEEP B1: why", because the finding is wrong or because the owner asked
 * for exactly that. The agent never decides: the finding holds the change
 * back until the owner agrees. A finding is argued for once per change, so
 * after a no the agent must fix it.
 */
class ProposeFindings
{
    /**
     * The longest reason kept, so one reply cannot fill the proof.
     */
    protected const REASON = 500;

    /**
     * Give each finding of the gate the key the agent answers with. A
     * finding the owner already said no to must be fixed, so it gets no key.
     *
     * @param  list<array{kind: string, identity: string, text: string}>  $findings
     * @return list<array{key: string|null, kind: string, identity: string, text: string}>
     */
    public function keyed(FeatureRequest $featureRequest, array $findings): array
    {
        $refused = $featureRequest->findingProposals()->where('agreed', false)->pluck('identity')->all();
        $keyed = [];
        $n = 0;

        foreach ($findings as $finding) {
            $key = in_array($finding['identity'], $refused, true) ? null : 'B'.++$n;

            $keyed[] = [...$finding, 'key' => $key, 'text' => $key === null
                ? __(':text You asked to keep this before, and the owner said it must be fixed.', ['text' => $finding['text']])
                : "{$key}: {$finding['text']}"];
        }

        return $keyed;
    }

    /**
     * Read the agent's reply for its cases, and keep each one for the owner.
     *
     * @return list<FindingProposal>
     */
    public function fromReply(Run $run, string $reply): array
    {
        $gate = $run->feedback['gate'] ?? [];

        if ($gate === [] || preg_match_all('/^\W*KEEP\s+(B\d+)\s*:\s*(.+)$/mi', $reply, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $proposed = [];

        foreach ($matches as [, $key, $reason]) {
            $finding = collect($gate)->firstWhere('key', strtoupper($key));

            if (! is_array($finding) || $run->featureRequest->findingProposals()->where('identity', $finding['identity'])->exists()) {
                continue;
            }

            $proposed[] = $run->featureRequest->findingProposals()->create([
                'run_id' => $run->id,
                'kind' => $finding['kind'],
                'identity' => $finding['identity'],
                'reason' => Str::limit(trim($reason), self::REASON),
            ]);
        }

        return $proposed;
    }

    /**
     * Get what the agent asked to keep that the owner has not answered yet,
     * by what each finding is.
     *
     * @return list<string>
     */
    public function pending(?FeatureRequest $featureRequest): array
    {
        return array_values($featureRequest?->findingProposals()->whereNull('agreed')->pluck('identity')->map(fn (mixed $identity) => (string) $identity)->all() ?? []);
    }
}
