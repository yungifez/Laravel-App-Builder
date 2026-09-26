<?php

namespace App\Console\Commands;

use App\Actions\Decisions\ObserveOutcome;
use App\Models\Decision;
use App\Models\FeatureRequest;
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
     * often it was sure and how often being sure was right.
     */
    public function handle(ObserveOutcome $observeOutcome): int
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
            ];
        }

        if ($rows === []) {
            $this->components->info('No decisions have been made yet.');

            return self::SUCCESS;
        }

        $this->table(['Decision', 'Made', 'Outcome known', 'Confident (of known)', 'Confident and right', 'Confident and wrong', 'Average time'], $rows);

        return self::SUCCESS;
    }
}
