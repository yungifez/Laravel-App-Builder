<?php

namespace App\Console\Commands;

use App\Evaluation\Conditions;
use App\Evaluation\Evidence;
use App\Evaluation\Handoff;
use App\Evaluation\PipelineHarness;
use App\Evaluation\Results;
use App\Evaluation\Suite;
use App\Evaluation\Workbench;
use App\Features\PatchSummary;
use App\Models\Run;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('eval:conditions {task : The task key from the suite manifest} {--arm=* : Only these arms (pipeline, plain)}')]
#[Description('Run the three verification conditions on every frozen snapshot of a task: each arm\'s change as made, and with each sabotage added')]
class EvaluateConditions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Conditions $conditions): int
    {
        PipelineHarness::configure();

        $suite = Suite::fromConfig();
        $task = (string) $this->argument('task');
        $results = Results::fromConfig();
        $runData = $results->json("{$task}/pipeline/run.json");

        if ($runData === null) {
            $this->error("Run [{$task}] through the pipeline first: the behaviour-aware condition uses its plan.");

            return self::FAILURE;
        }

        $run = Run::query()->findOrFail((int) $runData['run_id']);
        $request = $suite->prompt($task);
        $requirements = Suite::requirements(Suite::resolve((string) config('evaluation.project')));

        /** @var list<string> $arms */
        $arms = $this->option('arm') ?: ['pipeline', 'plain'];

        foreach ($arms as $arm) {
            $patch = $results->text("{$task}/{$arm}/patch.diff");

            if ($patch === null) {
                $this->warn("No [{$arm}] change for [{$task}] yet; skipped.");

                continue;
            }

            foreach ([null, ...$suite->sabotage()] as $sabotage) {
                $snapshot = $sabotage['key'] ?? 'natural';
                $this->info("[{$task}] {$arm} / {$snapshot}");

                $workbench = Workbench::create('cond-'.substr(md5("{$task}-{$arm}-{$snapshot}"), 0, 12));

                try {
                    $applied = $patch === '' || $workbench->apply($patch)->successful();
                    $sabotagePatch = $sabotage === null ? null : $suite->sabotagePatch($sabotage['patch']);

                    if ($applied && $sabotagePatch !== null) {
                        $applied = $workbench->apply($sabotagePatch, 'sabotage')->successful();
                    }

                    if (! $applied) {
                        $results->put("{$task}/{$arm}/conditions/{$snapshot}.json", ['applicable' => false]);

                        continue;
                    }

                    $diff = $workbench->diff();
                    $hidden = Evidence::hidden($workbench, $suite, $task);
                    $outcome = Handoff::within(
                        ['task' => $task, 'arm' => $arm, 'snapshot' => $snapshot],
                        fn () => $conditions->run($workbench, $diff, $request, $requirements, $run),
                    );

                    $results->put("{$task}/{$arm}/conditions/{$snapshot}.json", [
                        'applicable' => true,
                        'ground_truth' => [
                            'sabotage' => $sabotage['key'] ?? null,
                            'sabotage_area' => $sabotage['area'] ?? null,
                            'sabotage_covered' => $sabotage['covered_by_tests'] ?? null,
                            'sabotage_files' => $sabotagePatch === null ? [] : array_column(PatchSummary::files($sabotagePatch), 'path'),
                            'hidden' => $hidden,
                            'defective' => $sabotage !== null || $hidden['outcome'] !== 'passed',
                        ],
                        ...$outcome,
                    ]);
                } finally {
                    $workbench->destroy();
                }
            }
        }

        return self::SUCCESS;
    }
}
