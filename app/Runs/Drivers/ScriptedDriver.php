<?php

namespace App\Runs\Drivers;

use App\Features\FeatureGeneratorManager;
use App\Models\Run;
use App\Runs\BuiltChange;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Exceptions\ConstructionFailed;
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

    public function build(Run $run, ToolSession $tools): BuiltChange
    {
        $featureRequest = $run->featureRequest;
        $change = $this->generators->driver($featureRequest->generator)->generate($featureRequest);

        $listing = $tools->call('scripted:list-files', 'list_files');

        if (! $listing->succeeded()) {
            throw new ConstructionFailed(__('The project files could not be listed: :reason', ['reason' => $listing->error]));
        }

        $applied = $tools->call('scripted:apply-patch', 'apply_patch', ['patch' => $change->patch], $tools->revision());

        if (! $applied->succeeded()) {
            throw new ConstructionFailed(__('The change could not be made: :reason', ['reason' => $applied->error]));
        }

        return new BuiltChange(
            summary: $change->summary,
            steps: $change->steps,
            acceptance: $change->acceptance,
            solutionKey: $change->solutionKey,
        );
    }
}
