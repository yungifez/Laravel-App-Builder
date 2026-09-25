<?php

namespace App\Runs\Drivers;

use App\Features\FeatureGeneratorManager;
use App\Models\Run;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Plan;
use App\Runs\PlanningContext;
use App\Runs\Review;
use App\Runs\ReviewEvidence;
use App\Runs\ToolSession;

/**
 * Builds the change with a fixed tool sequence: look at the project, then
 * apply the feature generator's patch at the current workspace revision.
 *
 * It exercises the whole construction pipeline (lease, journal, tools,
 * verification) without model calls. Operation keys are fixed, so a
 * duplicate or resumed delivery replays the journal instead of acting twice.
 */
class ScriptedDriver implements ConstructionDriver
{
    public function __construct(protected FeatureGeneratorManager $generators) {}

    public function plan(Run $run, PlanningContext $context): Plan
    {
        $change = $this->generators->driver($run->featureRequest->generator)->generate($run->featureRequest);

        return new Plan(
            summary: $change->summary,
            acceptanceCriteria: [],
            assumptions: [__('Replays the known-good solution ":key".', ['key' => $change->solutionKey])],
            tasks: [__('Apply the solution\'s patch.')],
            steps: $change->steps,
            acceptance: $change->acceptance,
            solutionKey: $change->solutionKey,
        );
    }

    public function build(Run $run, Plan $plan, ToolSession $tools): string
    {
        $change = $this->generators->driver($run->featureRequest->generator)->generate($run->featureRequest);

        $listing = $tools->call('scripted:list-files', 'list_files');

        if (! $listing->succeeded()) {
            throw new ConstructionFailed(__('The project files could not be listed: :reason', ['reason' => $listing->error]));
        }

        $applied = $tools->call('scripted:apply-patch', 'apply_patch', ['patch' => $change->patch], $tools->revision());

        if (! $applied->succeeded()) {
            throw new ConstructionFailed(__('The change could not be made: :reason', ['reason' => $applied->error]));
        }

        return __('Applied the solution\'s patch.');
    }

    public function review(Run $run, ReviewEvidence $evidence): Review
    {
        return new Review(true, __('The scripted driver accepts a change that passed verification.'));
    }

    public function canRepair(): bool
    {
        return false;
    }
}
