<?php

namespace App\Actions\Operations;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\RunEvent;
use App\Operations\ModelCalls;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SummarizeSpend
{
    /**
     * Sum the model spend recorded since a time, keeping apart what the
     * provider reported, what we estimated from our prices, and calls with
     * no known cost. The completeness label says whether the total can be
     * trusted: a call without a price, or a call that is not metered at all,
     * makes the total a lower bound. Infrastructure (boxes, hosting) is not
     * recorded anywhere yet, so it is never part of the total.
     *
     * @return array{calls: int, unpriced_calls: int, reported_usd: float, estimated_usd: float, total_usd: float, input_tokens: int, output_tokens: int, decision_calls: int, setup_calls: int, setup_usd: float, undated_setup_calls: int, unmetered_decision_calls: int, completeness: string}
     */
    public function handle(CarbonImmutable $since): array
    {
        $source = ModelCalls::SOURCE_SQL;

        /** @var object{calls: int|string, unpriced: int|string, reported: float|string|null, estimated: float|string|null, input_tokens: int|string|null, output_tokens: int|string|null} $row */
        $row = RunEvent::query()
            ->where('type', 'model_call')
            ->where('created_at', '>=', $since)
            ->toBase()
            ->selectRaw("count(*) as calls,
                count(*) filter (where ({$source}) is null) as unpriced,
                sum((data->>'cost_usd')::numeric) filter (where ({$source}) = 'reported') as reported,
                sum((data->>'cost_usd')::numeric) filter (where ({$source}) = 'estimated') as estimated,
                sum(coalesce((data->>'input_tokens')::bigint, 0)) as input_tokens,
                sum(coalesce((data->>'output_tokens')::bigint, 0)) as output_tokens")
            ->first();

        [$setupCalls, $setupUsd, $setupUnpriced, $undated] = $this->setup($since);

        /** @var object{calls: int|string, unpriced: int|string, estimated: float|string|null, input_tokens: int|string|null, output_tokens: int|string|null} $decided */
        $decided = FeatureRequest::query()
            ->toBase()
            ->crossJoin(DB::raw('jsonb_array_elements(feature_requests.decision_model_calls::jsonb) as call'))
            ->whereRaw("(call->>'at')::timestamptz >= ?", [$since])
            ->selectRaw("count(*) as calls,
                count(*) filter (where (call->>'cost_usd') is null) as unpriced,
                sum((call->>'cost_usd')::numeric) as estimated,
                sum(coalesce((call->>'input_tokens')::bigint, 0)) as input_tokens,
                sum(coalesce((call->>'output_tokens')::bigint, 0)) as output_tokens")
            ->first();

        // Requests decided on before decision calls were metered: the model
        // was called, but what it cost is unknown.
        $decisions = FeatureRequest::query()
            ->whereNull('decision_model_calls')
            ->whereHas('decisions', fn (Builder $decisions) => $decisions->where('created_at', '>=', $since)->whereNotNull('model'))
            ->count();

        $calls = (int) $row->calls + $setupCalls + (int) $decided->calls;
        $unpriced = (int) $row->unpriced + $setupUnpriced + (int) $decided->unpriced;
        $reported = round((float) $row->reported, 4);
        $estimated = round((float) $row->estimated + $setupUsd + (float) $decided->estimated, 4);

        return [
            'calls' => $calls,
            'unpriced_calls' => $unpriced,
            'reported_usd' => $reported,
            'estimated_usd' => $estimated,
            'total_usd' => round($reported + $estimated, 4),
            'input_tokens' => (int) $row->input_tokens + (int) $decided->input_tokens,
            'output_tokens' => (int) $row->output_tokens + (int) $decided->output_tokens,
            'decision_calls' => (int) $decided->calls,
            'setup_calls' => $setupCalls,
            'setup_usd' => round($setupUsd, 4),
            'undated_setup_calls' => $undated,
            'unmetered_decision_calls' => $decisions,
            'completeness' => match (true) {
                $calls === 0 && $decisions === 0 => 'none',
                $unpriced === 0 && $decisions === 0 && $undated === 0 => 'complete',
                default => 'partial',
            },
        ];
    }

    /**
     * Sum the calls made to set projects up since a time. They live on the
     * project; calls recorded before they carried a time are counted apart.
     *
     * @return array{int, float, int, int}
     */
    protected function setup(CarbonImmutable $since): array
    {
        $calls = 0;
        $usd = 0.0;
        $unpriced = 0;
        $undated = 0;

        Project::query()->whereNotNull('setup_model_calls')->select(['id', 'setup_model_calls'])->each(function (Project $project) use ($since, &$calls, &$usd, &$unpriced, &$undated) {
            foreach ($project->setup_model_calls ?? [] as $call) {
                if (! isset($call['at'])) {
                    $undated++;

                    continue;
                }

                if (CarbonImmutable::parse($call['at'])->lt($since)) {
                    continue;
                }

                $calls++;

                if (is_numeric($call['cost_usd'] ?? null)) {
                    $usd += (float) $call['cost_usd'];
                } else {
                    $unpriced++;
                }
            }
        });

        return [$calls, $usd, $unpriced, $undated];
    }
}
