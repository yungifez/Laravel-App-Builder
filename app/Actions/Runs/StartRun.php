<?php

namespace App\Actions\Runs;

use App\Actions\Decisions\MakeDecisions;
use App\Actions\Projects\ConnectOwnTool;
use App\Enums\RunStatus;
use App\Jobs\DecideFeatureRequest;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Operations\ExecutionSettings;
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
            // The owner's own tool writes the app's changes while it is
            // connected; we still plan, check and review them.
            $theirs = ConnectOwnTool::connected($featureRequest->project);

            $run = $featureRequest->runs()->create([
                'driver' => $theirs ? 'worker' : $this->drivers->getDefaultDriver(),
                'config_version' => ExecutionSettings::record(),
                'status' => RunStatus::Queued,
                'question_limit' => (int) config('builder.construction.questions.before_building'),
            ]);

            $run->recordEvent('created', ['driver' => $run->driver, 'config_version' => $run->config_version]);

            if ($theirs) {
                $run->recordEvent('handed_to_owner', ['driver' => 'worker']);
            }

            // Queued before the run: once decisions act, they must be known
            // before the run starts, and the call takes about a second.
            if (MakeDecisions::providers() !== [] && ! $featureRequest->decisions()->exists()) {
                DecideFeatureRequest::dispatch($featureRequest)->afterCommit();
            }

            ExecuteRun::dispatch($run)->afterCommit();

            return $run;
        });
    }
}
