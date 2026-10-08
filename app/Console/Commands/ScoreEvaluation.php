<?php

namespace App\Console\Commands;

use App\Evaluation\Evidence;
use App\Evaluation\PipelineHarness;
use App\Evaluation\ReportWriter;
use App\Evaluation\Results;
use App\Evaluation\Suite;
use App\Evaluation\Workbench;
use App\Features\PatchSummary;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('eval:score {task : The task key from the suite manifest} {--arm=* : Only these arms (pipeline, plain)}')]
#[Description('Score an evaluation task: hidden tests on each arm\'s change, then each sabotage through each arm\'s own verification and report')]
class ScoreEvaluation extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PipelineHarness $harness, ReportWriter $reports): int
    {
        $suite = Suite::fromConfig();
        $task = (string) $this->argument('task');
        $results = Results::fromConfig();

        /** @var list<string> $arms */
        $arms = $this->option('arm') ?: ['pipeline', 'plain'];

        foreach ($arms as $arm) {
            $patch = $results->text("{$task}/{$arm}/patch.diff");

            if ($patch === null) {
                $this->warn("No [{$arm}] change for [{$task}] yet; skipped.");

                continue;
            }

            $this->info("Scoring [{$arm}] on [{$task}].");
            $this->natural($suite, $results, $reports, $task, $arm, $patch);

            foreach ($suite->sabotage() as $sabotage) {
                $this->sabotage($harness, $suite, $results, $reports, $task, $arm, $patch, $sabotage);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Outcome 1: the change as the arm made it, against the hidden tests and
     * the project's own checks.
     */
    protected function natural(Suite $suite, Results $results, ReportWriter $reports, string $task, string $arm, string $patch): void
    {
        $workbench = Workbench::create("score-{$task}-{$arm}");

        try {
            $applied = $patch === '' || $workbench->apply($patch)->successful();
            $checks = $applied ? Evidence::checks($workbench) : [];

            $results->put("{$task}/{$arm}/natural.json", [
                'applied' => $applied,
                'empty' => $patch === '',
                'hidden' => $applied ? Evidence::hidden($workbench, $suite, $task) : null,
                'checks' => $checks,
            ]);

            if ($arm === 'plain') {
                $summary = (string) ($results->json("{$task}/plain/agent.json")['summary'] ?? '');

                $results->put("{$task}/plain/report.md", $reports->plain($summary, $checks));
                $results->put("{$task}/structured/report.md", $reports->structured($suite->task($task)['request'], $summary, array_column(PatchSummary::files($patch), 'path'), $checks));
            }
        } finally {
            $workbench->destroy();
        }
    }

    /**
     * Outcome 2: a defect added after the arm finished, put through the arm's
     * own verification and report.
     *
     * @param  array{key: string, patch: string, area: string, covered_by_tests: bool, description: string, honest_report: string}  $sabotage
     */
    protected function sabotage(PipelineHarness $harness, Suite $suite, Results $results, ReportWriter $reports, string $task, string $arm, string $patch, array $sabotage): void
    {
        $workbench = Workbench::create("sabotage-{$task}-{$arm}-".substr(md5($sabotage['key']), 0, 8));
        $path = "{$task}/{$arm}/sabotage/{$sabotage['key']}";

        try {
            if (($patch !== '' && ! $workbench->apply($patch)->successful()) || ! $workbench->apply($suite->sabotagePatch($sabotage['patch']), 'sabotage')->successful()) {
                $results->put("{$path}.json", ['applicable' => false]);

                return;
            }

            $combined = $workbench->diff();

            if ($arm === 'plain') {
                $checks = Evidence::checks($workbench);
                $summary = (string) ($results->json("{$task}/plain/agent.json")['summary'] ?? '');

                $results->put("{$path}.json", ['applicable' => true, 'checks' => $checks]);
                $results->put("{$path}.md", $reports->plain($summary, $checks));
                $results->put("{$task}/structured/sabotage/{$sabotage['key']}.md", $reports->structured($suite->task($task)['request'], $summary, array_column(PatchSummary::files($combined), 'path'), $checks));

                return;
            }

            PipelineHarness::configure();

            $runData = $results->json("{$task}/pipeline/run.json") ?? [];
            $run = Run::query()->findOrFail((int) ($runData['run_id'] ?? 0));
            $verification = $harness->verify(FeatureRequest::query()->findOrFail((int) $runData['feature_request_id']), $combined);
            $review = $harness->review($run, $verification->status->value, $verification->results ?? [], $combined);

            $results->put("{$path}.json", [
                'applicable' => true,
                'verification' => ['status' => $verification->status->value, 'results' => $verification->results ?? []],
                'review' => $review,
            ]);
            $results->put("{$path}.md", $reports->pipeline(
                $suite->task($task)['request'],
                is_array($runData['plan'] ?? null) ? $runData['plan'] : [],
                $review,
                $verification->status->value,
                $verification->results ?? [],
            ));
        } finally {
            $workbench->destroy();
        }
    }
}
