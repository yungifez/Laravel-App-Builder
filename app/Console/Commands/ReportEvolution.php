<?php

namespace App\Console\Commands;

use App\Actions\Projects\MeasureEvolution;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('builder:evolution {project : The project, by id} {--json : Print the windows as JSON}')]
#[Description('Show how a project\'s changes went as the app grew, at fixed checkpoints')]
class ReportEvolution extends Command
{
    /**
     * Making one app is easy; keeping change 35 as good as change 5 is the
     * hard part (direction 21 §15). Each row is the kept changes up to a
     * checkpoint, with the work that led to them, per kept change.
     */
    public function handle(MeasureEvolution $measureEvolution): int
    {
        $project = Project::query()->find($this->argument('project'));

        if ($project === null) {
            $this->components->error('No project has that id.');

            return self::FAILURE;
        }

        /** @var list<int> $checkpoints */
        $checkpoints = config('builder.benchmark.checkpoints');
        $windows = $measureEvolution->handle($project, $checkpoints);

        if ($this->option('json')) {
            $this->line((string) json_encode($windows, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($windows === []) {
            $this->components->info('The project has no kept changes yet.');

            return self::SUCCESS;
        }

        $per = fn (int|float $value, int $kept) => round($value / $kept, 2);

        $this->table(
            ['Changes', 'Cost per change', 'Tokens per change', 'First try passed', 'Repairs per change', 'Owner steps per change', 'Tests broken (caught)', 'Earlier rules broken', 'Undone', 'Hours to keep (median)'],
            array_map(fn (array $window) => [
                $window['from'] === $window['to'] ? (string) $window['to'] : "{$window['from']}–{$window['to']}",
                '$'.number_format($window['cost_usd'] / $window['kept'], 2).($window['unpriced_calls'] > 0 ? " (+{$window['unpriced_calls']} unpriced)" : ''),
                number_format($window['tokens'] / $window['kept']),
                $window['first_attempts'] === 0 ? '-' : "{$window['first_attempt_passed']} of {$window['first_attempts']}",
                $per($window['repairs'], $window['kept']),
                $per($window['owner_steps'], $window['kept']),
                $window['broken_tests'],
                $window['earlier_rules_broken'],
                $window['undone'],
                $window['hours_to_keep'] ?? '-',
            ], $windows),
        );

        return self::SUCCESS;
    }
}
