<?php

namespace App\Actions\Runs;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\ConstructionDriverManager;
use Illuminate\Support\Facades\DB;

class StartRun
{
    public function __construct(private ConstructionDriverManager $drivers) {}

    /**
     * Queue a construction run for the feature request.
     */
    public function handle(FeatureRequest $featureRequest): Run
    {
        return DB::transaction(function () use ($featureRequest) {
            $run = $featureRequest->runs()->create([
                'driver' => $this->drivers->getDefaultDriver(),
                'status' => RunStatus::Queued,
                'question_limit' => (int) config('builder.construction.questions.before_building'),
            ]);

            $run->recordEvent('created', ['driver' => $run->driver]);

            ExecuteRun::dispatch($run)->afterCommit();

            return $run;
        });
    }
}
