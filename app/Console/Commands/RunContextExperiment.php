<?php

namespace App\Console\Commands;

use App\Actions\Context\CompileContext;
use App\Actions\Context\MeasureContextTrial;
use App\Actions\Features\DismissFeatureRequest;
use App\Actions\Features\RequestFeature;
use App\Actions\Runs\AwaitChange;
use App\Enums\ContextMode;
use App\Models\ContextTrial;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;
use Illuminate\Support\Facades\Context;

#[Signature('builder:context-experiment {project : The project, by id} {--modes=flat,selective : The ways to give the agent its context, comma-separated} {--changes=1 : How many of the next benchmark changes to make}')]
#[Description('Make the next benchmark changes once per way of giving the agent its context, and record how each went')]
class RunContextExperiment extends Command
{
    /**
     * Run the context experiment (architecture §26.7, risk B in §27.7). For
     * each next change of the benchmark, the same request is made on the
     * same commit once per mode, and none is kept, so each mode gets the
     * same app. Then the selective one, the product, is kept so the next
     * request builds on it, and the others are put away. Every trial spends
     * real model calls.
     */
    public function handle(RequestFeature $requestFeature, AwaitChange $awaitChange, MeasureContextTrial $measureContextTrial, DismissFeatureRequest $dismissFeatureRequest): int
    {
        $project = Project::query()->find($this->argument('project'));

        if ($project === null) {
            $this->components->error('No project has that id.');

            return self::FAILURE;
        }

        $modes = $this->modes();

        if ($modes === null) {
            $this->components->error('Give the modes from: '.implode(', ', array_column(ContextMode::cases(), 'value')).'. Selective must be one, as it is kept.');

            return self::FAILURE;
        }

        /** @var list<string> $sequence */
        $sequence = config('builder.benchmark.changes');
        $owner = $project->owner;
        $kept = $project->featureRequests()->whereNotNull('commit_sha')->whereNull('reverted_at')->pluck('prompt')->all();
        $next = array_values(array_filter($sequence, fn (string $prompt) => ! in_array($prompt, $kept, true)));

        if ($next === []) {
            $this->components->info('Every change of the benchmark is kept in this project.');

            return self::SUCCESS;
        }

        foreach (array_slice($next, 0, max(1, (int) $this->option('changes'))) as $prompt) {
            $round = (int) ContextTrial::query()->where('project_id', $project->id)->max('round') + 1;
            $trials = [];

            foreach ($modes as $mode) {
                $this->components->task("Round {$round}, {$mode->value}: {$prompt}", function () use ($requestFeature, $awaitChange, $measureContextTrial, $project, $owner, $prompt, $round, $mode, &$trials) {
                    $trials[$mode->value] = $this->trial($requestFeature, $awaitChange, $measureContextTrial, $project, $owner, $prompt, $round, $mode);

                    return $trials[$mode->value]->outcome === ContextTrial::OUTCOME_COMPLETED ? TaskResult::Success->value : TaskResult::Failure->value;
                });
            }

            $selective = $trials[ContextMode::Selective->value];

            foreach ($trials as $trial) {
                if ($trial !== $selective) {
                    $dismissFeatureRequest->handle($trial->featureRequest);
                }
            }

            $outcome = $selective->outcome === ContextTrial::OUTCOME_COMPLETED
                ? $awaitChange->handle($selective->featureRequest, $owner, keep: true)
                : ['reason' => 'The selective change did not complete, so there is nothing to build the next round on.'];

            if ($outcome['reason'] !== null) {
                $this->components->error($outcome['reason']);

                return self::FAILURE;
            }
        }

        $this->components->info("See the results with: php artisan builder:context-results {$project->id}");

        return self::SUCCESS;
    }

    /**
     * Make the request with the agent given its context one way, wait for
     * it, and record the trial.
     */
    protected function trial(RequestFeature $requestFeature, AwaitChange $awaitChange, MeasureContextTrial $measureContextTrial, Project $project, User $owner, string $prompt, int $round, ContextMode $mode): ContextTrial
    {
        // The queued work that builds the change carries the mode with it.
        Context::addHidden(CompileContext::TRIAL_MODE, $mode->value);

        try {
            $request = $requestFeature->handle($project, $owner, $prompt);
        } finally {
            Context::forgetHidden(CompileContext::TRIAL_MODE);
        }

        $awaited = $awaitChange->handle($request, $owner, keep: false);
        $measured = $measureContextTrial->handle($awaited['request']);
        $outcome = match (true) {
            $measured['modes'] !== [] && $measured['modes'] !== [$mode->value] => ContextTrial::OUTCOME_WRONG_MODE,
            $awaited['outcome'] === 'completed' => ContextTrial::OUTCOME_COMPLETED,
            $awaited['outcome'] === 'timed_out' => ContextTrial::OUTCOME_TIMED_OUT,
            default => ContextTrial::OUTCOME_STOPPED,
        };

        return ContextTrial::query()->create([
            'project_id' => $project->id,
            'round' => $round,
            'prompt' => $prompt,
            'mode' => $mode,
            'feature_request_id' => $awaited['request']->id,
            'outcome' => $outcome,
            'measures' => $measured['measures'],
        ]);
    }

    /**
     * Read the modes asked for, or null when one is unknown or selective,
     * which is kept, is missing.
     *
     * @return list<ContextMode>|null
     */
    protected function modes(): ?array
    {
        $modes = array_map(fn (string $mode) => ContextMode::tryFrom(trim($mode)), explode(',', (string) $this->option('modes')));

        if (in_array(null, $modes, true) || ! in_array(ContextMode::Selective, $modes, true)) {
            return null;
        }

        return array_values(array_unique($modes, SORT_REGULAR));
    }
}
