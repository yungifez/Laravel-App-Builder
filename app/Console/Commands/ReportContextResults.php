<?php

namespace App\Console\Commands;

use App\Enums\ContextMode;
use App\Models\ContextTrial;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('builder:context-results {project : The project, by id} {--json : Print the results as JSON}')]
#[Description('Show how each way of giving the agent its context did in the context experiment')]
class ReportContextResults extends Command
{
    /**
     * Report the context experiment (architecture §26.7): each mode's
     * trials, then each mode against selective, pair by pair. A pair is a
     * round where both changes completed. Differences are medians of the
     * pairs, shown only once there are enough pairs to read as a trend.
     */
    public function handle(): int
    {
        $project = Project::query()->find($this->argument('project'));

        if ($project === null) {
            $this->components->error('No project has that id.');

            return self::FAILURE;
        }

        $all = ContextTrial::query()->where('project_id', $project->id)->oldest('id')->get();
        // A trial whose agent got another mode than it asked for measures nothing.
        $trials = $all->where('outcome', '!=', ContextTrial::OUTCOME_WRONG_MODE);
        $modes = $trials->groupBy(fn (ContextTrial $trial) => $trial->mode->value);
        $results = [
            'modes' => $modes->map(fn (Collection $group) => $this->summary($group))->all(),
            'pairs' => $modes->keys()->reject(fn (string $mode) => $mode === ContextMode::Selective->value)
                ->mapWithKeys(fn (string $mode) => [$mode => $this->paired($trials, $mode)])->all(),
            'wrong_mode' => $all->count() - $trials->count(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($trials->isEmpty()) {
            $this->components->info('No trials yet. Run builder:context-experiment first.');

            return self::SUCCESS;
        }

        $this->table(
            ['Mode', 'Trials', 'Completed', 'First checks passed', 'Checks passed', 'Median tokens', 'Median minutes', 'Median repairs', 'Cost'],
            collect($results['modes'])->map(fn (array $mode, string $name) => [
                $name,
                $mode['trials'],
                $mode['completed'],
                "{$mode['first_attempt_passed']} of {$mode['first_attempts']}",
                "{$mode['verified']} of {$mode['trials']}",
                $mode['tokens'] ?? '-',
                $mode['minutes'] ?? '-',
                $mode['repairs'] ?? '-',
                '$'.number_format($mode['cost_usd'], 2).($mode['unpriced_calls'] > 0 ? " ({$mode['unpriced_calls']} calls unpriced)" : ''),
            ])->values()->all(),
        );

        if ($results['pairs'] !== []) {
            $this->newLine();
            $this->line('  Against selective, the median difference per pair (more is the other mode spending more):');
            $this->table(
                ['Mode', 'Pairs', 'Tokens', 'Minutes', 'Repairs', 'Areas changed unasked', 'Cost', 'First checks: better / worse'],
                collect($results['pairs'])->map(fn (array $pair, string $name) => $pair['enough']
                    ? [$name, $pair['pairs'], $pair['tokens'], $pair['minutes'] ?? '-', $pair['repairs'], $pair['unexpected_areas'] ?? '-', $pair['cost_usd'], "{$pair['first_better']} / {$pair['first_worse']}"]
                    : [$name, $pair['pairs'], 'too few pairs', '', '', '', '', ''])->values()->all(),
            );
        }

        if ($results['wrong_mode'] > 0) {
            $this->components->warn("{$results['wrong_mode']} trials got another mode than they asked for and are left out.");
        }

        return self::SUCCESS;
    }

    /**
     * Sum up one mode's trials.
     *
     * @param  Collection<int, ContextTrial>  $trials
     * @return array{trials: int, completed: int, first_attempt_passed: int, first_attempts: int, verified: int, tokens: float|int|null, minutes: float|int|null, repairs: float|int|null, cost_usd: float, unpriced_calls: int}
     */
    protected function summary(Collection $trials): array
    {
        $measures = $trials->pluck('measures');

        return [
            'trials' => $trials->count(),
            'completed' => $trials->where('outcome', ContextTrial::OUTCOME_COMPLETED)->count(),
            'first_attempt_passed' => $measures->where('first_attempt_passed', true)->count(),
            'first_attempts' => $measures->whereNotNull('first_attempt_passed')->count(),
            'verified' => $measures->where('verified', true)->count(),
            'tokens' => $measures->median('tokens'),
            'minutes' => $measures->whereNotNull('minutes')->median('minutes'),
            'repairs' => $measures->median('repairs'),
            'cost_usd' => round((float) $measures->sum('cost_usd'), 4),
            'unpriced_calls' => (int) $measures->sum('unpriced_calls'),
        ];
    }

    /**
     * Compare a mode with selective over the rounds where both completed.
     *
     * @param  Collection<int, ContextTrial>  $trials
     * @return array{pairs: int, enough: bool, tokens: float|int|null, minutes: float|int|null, repairs: float|int|null, unexpected_areas: float|int|null, cost_usd: float|int|null, first_better: int, first_worse: int}
     */
    protected function paired(Collection $trials, string $mode): array
    {
        $completed = $trials->where('outcome', ContextTrial::OUTCOME_COMPLETED);
        $selective = $completed->filter(fn (ContextTrial $trial) => $trial->mode === ContextMode::Selective)->keyBy('round');
        $pairs = $completed->filter(fn (ContextTrial $trial) => $trial->mode->value === $mode && $selective->has($trial->round))
            ->map(fn (ContextTrial $trial) => [$trial->measures, $selective[$trial->round]->measures]);
        $difference = fn (string $measure) => $pairs
            ->filter(fn (array $pair) => $pair[0][$measure] !== null && $pair[1][$measure] !== null)
            ->map(fn (array $pair) => $pair[0][$measure] - $pair[1][$measure])
            ->median();

        return [
            'pairs' => $pairs->count(),
            'enough' => $pairs->count() >= (int) config('builder.context.experiment.min_pairs'),
            'tokens' => $difference('tokens'),
            'minutes' => $difference('minutes'),
            'repairs' => $difference('repairs'),
            'unexpected_areas' => $difference('unexpected_areas'),
            'cost_usd' => $difference('cost_usd'),
            'first_better' => $pairs->filter(fn (array $pair) => $pair[0]['first_attempt_passed'] === true && $pair[1]['first_attempt_passed'] === false)->count(),
            'first_worse' => $pairs->filter(fn (array $pair) => $pair[0]['first_attempt_passed'] === false && $pair[1]['first_attempt_passed'] === true)->count(),
        ];
    }
}
