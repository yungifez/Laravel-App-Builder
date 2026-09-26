<?php

namespace App\Jobs;

use App\Actions\Decisions\MakeDecisions;
use App\Models\FeatureRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Makes the decisions about a request beside its run. The decisions do not
 * act yet, so the run never waits for them, and a decision model that is
 * down only costs the comparison.
 */
class DecideFeatureRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public FeatureRequest $featureRequest) {}

    public function handle(MakeDecisions $makeDecisions): void
    {
        $makeDecisions->handle($this->featureRequest);
    }
}
