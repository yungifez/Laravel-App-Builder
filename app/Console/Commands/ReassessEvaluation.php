<?php

namespace App\Console\Commands;

use App\Actions\Context\AssessPreservation;
use App\Context\ChangeClassification;
use App\Context\ContextPack;
use App\Context\ProjectContext;
use App\Evaluation\ReportWriter;
use App\Evaluation\Results;
use App\Evaluation\Suite;
use App\Models\Run;
use App\Models\Verification;
use App\Runs\Plan;
use App\Runs\Review;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('eval:reassess {into : Directory for the reassessed copy} {--from= : Results to reassess (defaults to the configured results)}')]
#[Description('Copy an evaluation\'s results and recompute only the pipeline\'s evidence labels and reports from the stored reviews and verifications, with no model calls')]
class ReassessEvaluation extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AssessPreservation $assessPreservation, ReportWriter $reports): int
    {
        $suite = Suite::fromConfig();
        $from = $this->option('from') !== null ? Suite::resolve((string) $this->option('from')) : Results::fromConfig()->directory;
        $into = Suite::resolve((string) $this->argument('into'));

        if (rtrim($into, DIRECTORY_SEPARATOR) === rtrim($from, DIRECTORY_SEPARATOR)) {
            $this->error('Reassess into a new directory; the original results are kept as they are.');

            return self::FAILURE;
        }

        File::deleteDirectory($into);
        File::copyDirectory($from, $into);
        File::deleteDirectory("{$into}/bundles");
        File::delete(["{$into}/summary.md", "{$into}/bundle-key.json"]);

        $results = new Results($into);

        foreach ($suite->taskKeys() as $task) {
            $runData = $results->json("{$task}/pipeline/run.json");

            if ($runData === null) {
                continue;
            }

            $run = Run::query()->findOrFail((int) $runData['run_id']);

            if ($run->plan === null) {
                $this->warn("Run #{$run->id} has no plan; [{$task}] is left as it was.");

                continue;
            }

            $plan = Plan::fromArray($run->plan);
            $context = $run->context !== null ? ContextPack::fromArray($run->context)->projectContext() : new ProjectContext;
            $request = $suite->task($task)['request'];

            // The change as made, with the run's own review and verification.
            $verification = Verification::query()->where('run_id', $run->id)->latest('id')->firstOrFail();
            $review = $this->reassessed($assessPreservation, $plan, $context, (array) $run->review, $verification->results ?? []);
            $results->put("{$task}/pipeline/run.json", [...$runData, 'review' => [...(array) ($runData['review'] ?? []), 'preserved' => $review['preserved']]]);
            $results->put("{$task}/pipeline/report.md", $reports->pipeline($request, (array) ($runData['plan'] ?? []), $review, $verification->status->value, $verification->results ?? []));

            // Each sabotaged change, with the review and verification stored for it.
            foreach ($suite->sabotage() as $sabotage) {
                $path = "{$task}/pipeline/sabotage/{$sabotage['key']}";
                $stored = $results->json("{$path}.json");

                if ($stored === null || ($stored['applicable'] ?? false) !== true) {
                    continue;
                }

                $review = $this->reassessed($assessPreservation, $plan, $context, (array) $stored['review'], (array) $stored['verification']['results']);
                $results->put("{$path}.json", [...$stored, 'review' => $review]);
                $results->put("{$path}.md", $reports->pipeline($request, (array) ($runData['plan'] ?? []), $review, (string) $stored['verification']['status'], (array) $stored['verification']['results']));
            }

            $this->info("Reassessed [{$task}].");
        }

        config(['evaluation.results' => $into]);
        $this->call('eval:report', ['--seed' => 2]);

        return self::SUCCESS;
    }

    /**
     * Recompute a stored review's preservation evidence.
     *
     * @param  array<string, mixed>  $review
     * @param  array<mixed>  $verificationResults
     * @return array<string, mixed>
     */
    protected function reassessed(AssessPreservation $assessPreservation, Plan $plan, ProjectContext $context, array $review, array $verificationResults): array
    {
        /** @var array{requested: array<string, list<string>>, may_also_affect: array<string, list<string>>, unexpected: array<string, list<string>>, unclaimed: list<string>, context_updates: list<string>, targets: list<string>} $classification */
        $classification = $review['classification'];

        /** @var list<array{severity: string, summary: string, file: string|null}> $findings */
        $findings = $review['findings'] ?? [];

        /** @var list<array{name: string, stage: string, outcome: string, output?: string}> $verificationResults */
        return [...$review, 'preserved' => $assessPreservation->handle(
            $plan,
            ChangeClassification::fromArray($classification),
            $context,
            $verificationResults,
            new Review((bool) ($review['approved'] ?? false), (string) ($review['summary'] ?? ''), $findings),
        )];
    }
}
