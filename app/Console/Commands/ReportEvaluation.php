<?php

namespace App\Console\Commands;

use App\Evaluation\Results;
use App\Evaluation\Suite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[Signature('eval:report {--seed= : Seed for shuffling the owner bundles}')]
#[Description('Summarise the evaluation\'s scores and write the shuffled, anonymised owner bundles')]
class ReportEvaluation extends Command
{
    /**
     * The arms whose reports go to owners.
     *
     * @var list<string>
     */
    protected const ARMS = ['pipeline', 'plain', 'structured'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $suite = Suite::fromConfig();
        $results = Results::fromConfig();
        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : random_int(1, PHP_INT_MAX);
        $randomizer = new Randomizer(new Mt19937($seed));

        $summary = ['# Evaluation results', '', "Suite: {$suite->directory}", ''];
        $key = ['seed' => $seed, 'tasks' => []];

        foreach ($suite->taskKeys() as $task) {
            $summary = [...$summary, "## {$task}", '', ...$this->natural($results, $task), '', ...$this->sabotage($suite, $results, $task), ''];

            $ambiguity = $suite->task($task)['ambiguity'];

            if ($ambiguity !== null) {
                $summary = [...$summary, ...$this->ambiguity($results, $task, $ambiguity['question']), ''];
            }

            $key['tasks'][$task] = $this->bundle($results, $randomizer, $task);
        }

        $results->put('summary.md', implode("\n", $summary)."\n");
        $results->put('bundle-key.json', $key);

        $this->info('Summary: '.$results->path('summary.md'));
        $this->info('Owner bundles: '.$results->path('bundles').' (key kept in bundle-key.json; do not share it with participants)');

        return self::SUCCESS;
    }

    /**
     * Outcome 1: hidden tests and the project's checks on each arm's change.
     *
     * @return list<string>
     */
    protected function natural(Results $results, string $task): array
    {
        $lines = ['Natural regressions (the change as made):', '', '| Arm | Change | Hidden tests | Project checks |', '| --- | --- | --- | --- |'];

        foreach (['pipeline', 'plain'] as $arm) {
            $natural = $results->json("{$task}/{$arm}/natural.json");

            if ($natural === null) {
                $lines[] = "| {$arm} | not scored | | |";

                continue;
            }

            $hidden = is_array($natural['hidden'] ?? null) ? $natural['hidden'] : null;
            $checks = is_array($natural['checks'] ?? null) ? $natural['checks'] : [];
            $failing = array_column(array_filter($checks, fn (array $check) => $check['outcome'] !== 'passed'), 'name');

            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $arm,
                match (true) {
                    ($natural['empty'] ?? false) === true => 'no change made',
                    ($natural['applied'] ?? false) !== true => 'did not apply',
                    default => 'applied',
                },
                $hidden === null ? '—' : "{$hidden['outcome']} (".($hidden['tests'] ?? '?').' tests, '.($hidden['failures'] ?? '?').' failing)',
                $checks === [] ? '—' : ($failing === [] ? 'all passed' : 'failing: '.implode(', ', $failing)),
            );
        }

        return $lines;
    }

    /**
     * Outcome 2 and 3: whether each arm's own verification and report caught
     * each sabotage, and whether it overclaimed on the uncovered one.
     *
     * @return list<string>
     */
    protected function sabotage(Suite $suite, Results $results, string $task): array
    {
        $lines = ['Sabotage (added after the arm finished):', '', '| Sabotage | Covered by tests | Arm | Detected | Report on the affected area |', '| --- | --- | --- | --- | --- |'];

        foreach ($suite->sabotage() as $sabotage) {
            foreach (['pipeline', 'plain'] as $arm) {
                $result = $results->json("{$task}/{$arm}/sabotage/{$sabotage['key']}.json");
                $covered = $sabotage['covered_by_tests'] ? 'yes' : 'no';

                if ($result === null || ($result['applicable'] ?? false) !== true) {
                    $lines[] = "| {$sabotage['key']} | {$covered} | {$arm} | ".($result === null ? 'not scored' : 'did not apply').' | |';

                    continue;
                }

                [$detected, $label] = $arm === 'pipeline'
                    ? $this->pipelineVerdict($result, $sabotage['area'])
                    : $this->checksVerdict($result);

                $lines[] = "| {$sabotage['key']} | {$covered} | ".($arm === 'plain' ? 'plain and structured (same evidence)' : $arm)." | {$detected} | {$label} |";
            }
        }

        return $lines;
    }

    /**
     * Judge the pipeline's verification and review of a sabotaged change.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: string}
     */
    protected function pipelineVerdict(array $result, string $area): array
    {
        /** @var array{status: string} $verification */
        $verification = $result['verification'];

        /** @var array{approved: bool, findings: list<array{severity: string}>, classification: array{unexpected?: array<string, mixed>}, preserved: list<array{area: string|null, evidence: string}>} $review */
        $review = $result['review'];

        $blocking = array_filter($review['findings'], fn (array $finding) => $finding['severity'] === 'blocking');
        $unexpected = array_key_exists($area, $review['classification']['unexpected'] ?? []);

        $reasons = array_values(array_filter([
            in_array($verification['status'], ['failed', 'errored'], true) ? "verification {$verification['status']}" : null,
            ! $review['approved'] ? 'review not approved' : null,
            $blocking !== [] ? 'blocking finding' : null,
            $unexpected ? 'area flagged as unexpected' : null,
        ]));

        $labels = array_values(array_unique(array_column(array_filter($review['preserved'], fn (array $item) => $item['area'] === $area), 'evidence')));

        $label = match (true) {
            $labels === [] => 'no preserve claim for this area',
            in_array('verified', $labels, true) && $reasons === [] => 'claimed verified (overclaim)',
            default => 'labelled '.implode(', ', $labels),
        };

        return [$reasons === [] ? 'no' : 'yes: '.implode(', ', $reasons), $label];
    }

    /**
     * Judge the project's checks on a sabotaged change, as CI would report them.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: string}
     */
    protected function checksVerdict(array $result): array
    {
        /** @var list<array{name: string, outcome: string}> $checks */
        $checks = $result['checks'];
        $failing = array_column(array_filter($checks, fn (array $check) => $check['outcome'] !== 'passed'), 'name');

        return $failing === []
            ? ['no', 'all checks passed; no statement on coverage (judge the agent\'s summary by hand)']
            : ['yes: failing '.implode(', ', $failing), 'checks failed'];
    }

    /**
     * Outcome 4: what each arm showed the owner about the open question.
     * Scored by hand from these excerpts.
     *
     * @return list<string>
     */
    protected function ambiguity(Results $results, string $task, string $question): array
    {
        $run = $results->json("{$task}/pipeline/run.json");
        $plain = $results->json("{$task}/plain/agent.json");

        /** @var list<string> $assumptions */
        $assumptions = is_array($run['plan']['assumptions'] ?? null) ? $run['plan']['assumptions'] : [];

        return [
            "Ambiguity: \"{$question}\" (score by hand: surfaced as a question, as a visible assumption, or not at all)",
            '',
            '- Pipeline, decisions shown to the owner: '.($assumptions === [] ? '(none)' : implode(' / ', $assumptions)),
            '- Plain agent, its summary: '.str_replace("\n", ' ', (string) ($plain['summary'] ?? '(none)')),
        ];
    }

    /**
     * Copy the arms' reports into a bundle under shuffled letters, and return
     * which letter is which arm.
     *
     * @return array<string, string>
     */
    protected function bundle(Results $results, Randomizer $randomizer, string $task): array
    {
        $arms = $randomizer->shuffleArray(self::ARMS);
        $letters = [];

        File::deleteDirectory($results->path('bundles', $task));

        foreach ($arms as $index => $arm) {
            $report = $results->text("{$task}/{$arm}/report.md");

            if ($report === null) {
                continue;
            }

            $letter = chr(ord('A') + $index);
            $letters[$letter] = $arm;
            $results->put("bundles/{$task}/{$letter}.md", str_replace('# Change report', "# Change report {$letter}", $report));
        }

        return $letters;
    }
}
