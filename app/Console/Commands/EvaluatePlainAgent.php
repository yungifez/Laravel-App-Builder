<?php

namespace App\Console\Commands;

use App\Evaluation\Handoff;
use App\Evaluation\Results;
use App\Evaluation\Suite;
use App\Evaluation\Workbench;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('eval:plain {task : The task key from the suite manifest}')]
#[Description('Have a plain coding agent make an evaluation task\'s change in a copy of the project (arm 2), through the hand-off')]
class EvaluatePlainAgent extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $handoff = Handoff::fromConfig();

        if ($handoff === null) {
            $this->error('Set BUILDER_EVAL_HANDOFF so the agent\'s work is handed off.');

            return self::FAILURE;
        }

        $suite = Suite::fromConfig();
        $task = (string) $this->argument('task');
        $results = Results::fromConfig();
        $workbench = Workbench::create("plain-{$task}");

        $this->info("Workspace ready at {$workbench->path}. Answer the hand-off.");

        $response = $handoff->ask('plain-coder', [
            'workspace' => $workbench->path,
            'prompt' => $suite->prompt($task),
        ]);

        $results->put("{$task}/plain/agent.json", [
            'status' => $response['status'] ?? null,
            'summary' => $response['summary'] ?? '',
        ]);
        $results->put("{$task}/plain/patch.diff", $workbench->diff());

        $this->info('Results in '.$results->path($task, 'plain'));

        return self::SUCCESS;
    }
}
