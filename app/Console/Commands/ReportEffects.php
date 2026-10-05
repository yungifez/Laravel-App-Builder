<?php

namespace App\Console\Commands;

use App\Actions\Context\ReadProjectContext;
use App\Context\ChangeClassification;
use App\Models\Project;
use App\Models\Run;
use App\Models\TestObservation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('builder:effects {--project= : Only this project} {--limit=30 : Changes to show, newest first}')]
#[Description('Compare what the tests showed a change could reach with what the change touched')]
class ReportEffects extends Command
{
    /**
     * The test-impact experiment (direction 22, §18): for real changes, the
     * areas whose tests ran the changed code, against the areas the change
     * touched outside the ask. "Missed" areas were touched but no test ran
     * into them; "unknown" is changed code no test ran; "foundation" is
     * changed code most tests run, which reaches everything; "narrowed by
     * line" counts files whose reach came from the changed lines alone.
     * Then, per project,
     * the behaviours no test proves: where the evidence is blind.
     */
    public function handle(ReadProjectContext $readProjectContext): int
    {
        $runs = Run::query()
            ->whereNotNull('review')
            ->with('featureRequest')
            ->when($this->option('project'), fn ($query, $project) => $query->whereHas('featureRequest', fn ($query) => $query->where('project_id', $project)))
            ->latest('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $rows = [];

        foreach ($runs as $run) {
            $classification = isset($run->review['classification']) ? ChangeClassification::fromArray($run->review['classification']) : null;

            if ($classification?->observed === null) {
                continue;
            }

            $reached = array_values(array_diff(array_keys($classification->observed['areas']), $classification->targets));
            $touched = array_values(array_diff($classification->touched(), $classification->targets));

            $rows[] = [
                "#{$run->feature_request_id}",
                implode(', ', $classification->targets) ?: '-',
                $classification->observed['tests'],
                implode(', ', $reached) ?: '-',
                implode(', ', $touched) ?: '-',
                implode(', ', array_diff($touched, $reached)) ?: '-',
                count($classification->observed['unmapped']),
                count($classification->observed['foundation']),
                count($classification->observed['by_line']),
            ];
        }

        if ($rows === []) {
            $this->components->info('No checked change has a test map yet.');
        } else {
            $this->table(['Change', 'Asked about', 'Tests reached', 'Areas reached', 'Touched outside the ask', 'Missed', 'Unknown files', 'Foundation files', 'Narrowed by line'], $rows);
        }

        $this->blindSpots($readProjectContext);

        return self::SUCCESS;
    }

    /**
     * List, per project with a test map, the behaviours no test proves and
     * the areas whose code no test ran.
     */
    protected function blindSpots(ReadProjectContext $readProjectContext): void
    {
        $projects = Project::query()
            ->whereHas('testObservations')
            ->when($this->option('project'), fn ($query, $project) => $query->whereKey($project))
            ->get();

        foreach ($projects as $project) {
            $map = TestObservation::latestFor($project)?->map();

            if ($map === null) {
                continue;
            }

            $context = $readProjectContext->current($project);
            $proven = $map->provenBehaviors();
            $foundation = $map->foundation();
            $rows = [];

            foreach ($context->capabilities as $capability) {
                $unproven = array_values(array_diff(array_column($capability->behaviors, 'key'), $proven));
                // Foundation code runs in almost every test, so it proves nothing about one area.
                $reached = array_filter(array_diff(array_keys($map->files), $foundation), fn (string $path) => $capability->claims($path));

                if ($unproven !== [] || $reached === []) {
                    $rows[] = [$capability->key, implode(', ', $unproven) ?: '-', $reached === [] ? 'no' : 'yes'];
                }
            }

            $this->newLine();
            $this->components->info("{$project->name}: where the tests are blind");

            $rows === []
                ? $this->line('  Every behaviour has a test, and tests run code in every area.')
                : $this->table(['Area', 'Behaviours no test proves', 'Tests run its code'], $rows);
        }
    }
}
