<?php

namespace App\Console\Commands;

use App\Actions\Context\PlantChange;
use App\Context\ContextPack;
use App\Features\PatchSummary;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('builder:planted-changes {project : The project, by id} {--json : Print the results as JSON}')]
#[Description('Show whether the review would catch an unrelated edit planted in each kept change, with and without Effects')]
class ReportPlantedChanges extends Command
{
    /**
     * Plant an unrelated edit in each kept change and classify it with and
     * without the project's Effects (architecture §26.7, hypothesis C). It
     * reads saved diffs and notes only: no model calls, no repository and
     * nothing saved. A pair is one plant classified both ways.
     */
    public function handle(PlantChange $plantChange): int
    {
        $project = Project::query()->find($this->argument('project'));

        if ($project === null) {
            $this->components->error('No project has that id.');

            return self::FAILURE;
        }

        $kept = $project->featureRequests()
            ->whereNotNull('commit_sha')
            ->whereNull('reverted_at')
            ->whereNotNull('patch')
            ->with('latestRun')
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (FeatureRequest $request) => $request->latestRun?->context !== null);

        $knownPaths = array_values(array_unique($kept->toBase()
            ->flatMap(fn (FeatureRequest $request) => array_column(PatchSummary::files($request->patch), 'path'))
            ->all()));

        $plants = $kept->toBase()->flatMap(function (FeatureRequest $request) use ($plantChange, $knownPaths) {
            $pack = ContextPack::fromArray($request->latestRun->context);

            return array_map(
                fn (array $plant) => ['change' => $request->id] + $plant,
                $plantChange->handle($pack->projectContext(), $pack->targets, (string) $request->patch, $knownPaths),
            );
        })->values();

        $withEffects = $this->places($plants, 'with_effects');
        $withoutEffects = $this->places($plants, 'without_effects');
        $enough = $plants->count() >= (int) config('builder.context.experiment.min_pairs');
        $explainedAway = $plants->filter(fn (array $plant) => $plant['without_effects'] === 'unexpected' && $plant['with_effects'] !== 'unexpected')->values()->all();
        $results = [
            'changes' => $kept->count(),
            'with_effects' => $withEffects,
            'without_effects' => $withoutEffects,
            'pairs' => $plants->count(),
            'enough' => $enough,
            'explained_away' => $explainedAway,
            'plants' => $plants->all(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($plants->isEmpty()) {
            $this->components->info('Nothing to plant yet. It needs kept changes and areas outside them that claim a known file.');

            return self::SUCCESS;
        }

        $this->table(
            ['', 'Caught as unexpected', 'Explained as may also affect', 'Not in any area'],
            [
                ['With Effects', ...array_values($withEffects)],
                ['Without Effects', ...array_values($withoutEffects)],
            ],
        );

        $explained = count($explainedAway);
        $caught = $withoutEffects['unexpected'];

        $this->newLine();
        $this->line($enough
            ? "  Of {$caught} plants caught without Effects, Effects explained away {$explained}."
            : "  {$plants->count()} plants: too few pairs.");

        foreach ($enough ? $explainedAway : [] as $plant) {
            $this->line("    change {$plant['change']}: {$plant['path']} ({$plant['area']})");
        }

        return self::SUCCESS;
    }

    /**
     * Count where the plants landed under one condition.
     *
     * @param  Collection<int, array{area: string, path: string, with_effects: string, without_effects: string, change: int}>  $plants
     * @return array{unexpected: int, may_also_affect: int, unclaimed: int}
     */
    protected function places(Collection $plants, string $condition): array
    {
        $counts = $plants->countBy($condition);

        return [
            'unexpected' => $counts->get('unexpected', 0),
            'may_also_affect' => $counts->get('may_also_affect', 0),
            'unclaimed' => $counts->get('unclaimed', 0),
        ];
    }
}
