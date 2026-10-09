<?php

namespace App\Console\Commands;

use App\Actions\Decisions\ObserveOutcome;
use App\Actions\Projects\MeasureChanges;
use App\Models\Decision;
use App\Models\FeatureRequest;
use App\Models\VisualEdit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('builder:decisions {--project= : Only this project\'s requests}')]
#[Description('Compare the decisions made before building with what actually happened')]
class ReportDecisions extends Command
{
    /**
     * A decision may start acting only when its confident answers are
     * rarely wrong (architecture §26.9). This shows, per decision, how
     * often it was sure and how often being sure was right. Once one acts,
     * its changes are set against the changes it left alone, so the
     * operator can see whether switching it on helped.
     */
    public function handle(ObserveOutcome $observeOutcome, MeasureChanges $measureChanges): int
    {
        $rows = [];
        $outcomes = [];

        $decisions = Decision::query()
            ->with('featureRequest.latestRun')
            ->when($this->option('project'), fn ($query, $project) => $query->whereHas('featureRequest', fn ($query) => $query->where('project_id', $project)))
            ->get();

        foreach ($decisions->groupBy('name') as $name => $group) {
            $known = $confident = $right = 0;

            foreach ($group as $decision) {
                /** @var FeatureRequest $request */
                $request = $decision->featureRequest;
                $observed = ($outcomes[$request->id] ??= $observeOutcome->handle($request))[$name] ?? null;

                if ($observed === null) {
                    continue;
                }

                $known++;

                if ($decision->confident()) {
                    $confident++;
                    $right += (int) ($decision->choice === $observed);
                }
            }

            $rows[] = [
                $name,
                $group->count(),
                $known,
                $confident,
                $confident > 0 ? round(100 * $right / $confident).'%' : '-',
                $confident - $right,
                round((float) $group->avg('latency_ms')).' ms',
                round(100 * $group->where('fallback', true)->count() / $group->count()).'%',
                // Unknown when no answer's model had a known price.
                $group->whereNotNull('cost_usd')->isEmpty() ? '-' : '$'.number_format((float) $group->sum('cost_usd'), 4),
            ];
        }

        if ($rows === []) {
            $this->components->info('No decisions have been made yet.');

            return self::SUCCESS;
        }

        $this->table(['Decision', 'Made', 'Outcome known', 'Confident (of known)', 'Confident and right', 'Confident and wrong', 'Average time', 'Second provider', 'Cost'], $rows);

        $compared = [];

        foreach ($decisions->groupBy('name') as $name => $group) {
            if (! $group->contains('acted', true)) {
                continue;
            }

            foreach (['Acted' => true, 'Left alone' => false] as $label => $acted) {
                $measured = $measureChanges->handle(
                    FeatureRequest::query()->whereKey($group->where('acted', $acted)->pluck('feature_request_id')->all()),
                    VisualEdit::query()->whereRaw('false'),
                );

                $compared[] = [
                    $name,
                    $label,
                    $group->where('acted', $acted)->count(),
                    $measured['runs_verified'] > 0 ? round(100 * $measured['first_attempt_passed'] / $measured['runs_verified']).'% of '.$measured['runs_verified'] : '-',
                    $measured['kept'],
                    $measured['cost_per_kept_change_usd'] === null ? '-' : '$'.number_format($measured['cost_per_kept_change_usd'], 2).($measured['unpriced_calls'] > 0 ? " (+{$measured['unpriced_calls']} unpriced)" : ''),
                ];
            }
        }

        if ($compared !== []) {
            $this->table(['Decision', 'Changes', 'Asked', 'First try passed', 'Kept', 'Cost per kept change'], $compared);
        }

        return self::SUCCESS;
    }
}
