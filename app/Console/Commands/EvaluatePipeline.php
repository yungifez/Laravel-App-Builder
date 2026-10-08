<?php

namespace App\Console\Commands;

use App\Evaluation\PipelineHarness;
use App\Evaluation\ReportWriter;
use App\Evaluation\Results;
use App\Evaluation\Suite;
use App\Http\Resources\RunResource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('eval:pipeline {task : The task key from the suite manifest}')]
#[Description('Run an evaluation task through the builder pipeline (arm 1), with model calls handed off')]
class EvaluatePipeline extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PipelineHarness $harness, ReportWriter $reports): int
    {
        PipelineHarness::configure();

        $suite = Suite::fromConfig();
        $task = (string) $this->argument('task');
        $results = Results::fromConfig();
        $prompt = $suite->prompt($task);

        $this->info("Running [{$task}] through the pipeline. Answer the hand-offs as they appear.");

        $run = $harness->run($prompt);
        $featureRequest = $run->featureRequest->refresh();
        $verification = $run->verifications()->latest('id')->first();
        $data = $run->toResource(RunResource::class)->resolve();

        $results->put("{$task}/pipeline/run.json", ['run_id' => $run->id, 'feature_request_id' => $featureRequest->id, ...$data]);
        $results->put("{$task}/pipeline/patch.diff", (string) $featureRequest->patch);
        $results->put("{$task}/pipeline/report.md", $reports->pipeline(
            $suite->task($task)['request'],
            is_array($data['plan'] ?? null) ? $data['plan'] : [],
            is_array($data['review'] ?? null) ? $data['review'] : null,
            $verification?->status->value ?? 'not run',
            $verification->results ?? [],
        ));

        $this->info("Run #{$run->id} ended as [{$run->status->value}]. Results in ".$results->path($task, 'pipeline'));

        return self::SUCCESS;
    }
}
