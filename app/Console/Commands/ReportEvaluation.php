<?php

namespace App\Console\Commands;

use App\Evaluation\Evidence;
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
     * Detection is measured against the arm's own change without sabotage,
     * so a change that already failed a check does not count as catching it.
     *
     * @return list<string>
     */
    protected function sabotage(Suite $suite, Results $results, string $task): array
    {
        $lines = ['Sabotage (added after the arm finished):', '', '| Sabotage | Covered by tests | Arm | Detected | Report on the affected area |', '| --- | --- | --- | --- | --- |'];
        $details = [];

        foreach ($suite->sabotage() as $sabotage) {
            foreach (['pipeline', 'plain'] as $arm) {
                $result = $results->json("{$task}/{$arm}/sabotage/{$sabotage['key']}.json");
                $covered = $sabotage['covered_by_tests'] ? 'yes' : 'no';
                $name = $arm === 'plain' ? 'plain and structured (same evidence)' : $arm;

                if ($result === null || ($result['applicable'] ?? false) !== true) {
                    $lines[] = "| {$sabotage['key']} | {$covered} | {$name} | ".($result === null ? 'not scored' : 'did not apply').' | |';

                    continue;
                }

                $natural = $results->json("{$task}/{$arm}/natural.json") ?? [];
                $baseline = $this->failing(is_array($natural['checks'] ?? null) ? $natural['checks'] : []);
                $checks = $arm === 'pipeline'
                    ? array_filter(is_array($result['verification']['results'] ?? null) ? $result['verification']['results'] : [], fn ($check) => is_array($check) && in_array($check['stage'] ?? '', ['checks', 'acceptance'], true))
                    : (is_array($result['checks'] ?? null) ? $result['checks'] : []);
                $failing = $this->failing($checks);
                $newChecks = array_values(array_diff($failing['checks'], $baseline['checks']));
                $newTests = array_values(array_diff($failing['tests'], $baseline['tests']));

                $reasons = array_values(array_filter([
                    $newChecks !== [] ? 'newly failing: '.implode(', ', $newChecks) : null,
                    $newTests !== [] ? count($newTests).' test(s) newly failing' : null,
                ]));

                $label = 'checks failed';

                if ($arm === 'pipeline') {
                    [$reviewReasons, $label] = $this->pipelineReview($result, $sabotage['area'], $reasons === []);
                    $reasons = [...$reasons, ...$reviewReasons];
                    $details[] = "- {$sabotage['key']}, pipeline review: ".(($result['review']['approved'] ?? false) ? 'approved' : 'not approved').'. '.str_replace("\n", ' ', (string) ($result['review']['summary'] ?? '')).$this->findings($result);
                } elseif ($reasons === []) {
                    $label = 'nothing newly failed; no statement on coverage (judge the agent\'s summary by hand)';
                }

                if ($newTests !== []) {
                    $details[] = "- {$sabotage['key']}, {$arm}, newly failing tests: ".implode('; ', $newTests);
                }

                $already = $baseline['checks'] === [] ? '' : ' (the change already failed: '.implode(', ', $baseline['checks']).')';

                $lines[] = "| {$sabotage['key']} | {$covered} | {$name} | ".($reasons === [] ? 'no' : 'yes: '.implode('; ', $reasons)).$already." | {$label} |";
            }
        }

        return $details === [] ? $lines : [...$lines, '', 'Details for judging by hand:', '', ...$details];
    }

    /**
     * Read the pipeline's review of a sabotaged change: whether it objected or
     * flagged the area, and what it said about the affected area.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: list<string>, 1: string}
     */
    protected function pipelineReview(array $result, string $area, bool $checksCaughtNothing): array
    {
        /** @var array{approved: bool, findings: list<array{severity: string}>, classification: array{unexpected?: array<string, mixed>}, preserved: list<array{area: string|null, evidence: string}>} $review */
        $review = $result['review'];

        $blocking = array_filter($review['findings'], fn (array $finding) => $finding['severity'] === 'blocking');
        $labels = array_values(array_unique(array_column(array_filter($review['preserved'], fn (array $item) => $item['area'] === $area), 'evidence')));

        $reasons = array_values(array_filter([
            ! $review['approved'] || $blocking !== [] ? 'review objected (check it names this defect)' : null,
            array_key_exists($area, $review['classification']['unexpected'] ?? []) ? 'area flagged as unexpected' : null,
            array_intersect($labels, ['regression_suspected', 'tests_failed']) !== [] ? 'evidence flagged the area' : null,
        ]));

        // Only the label used before the evidence was made stricter claims
        // that a behaviour held; the current labels never do.
        $label = match (true) {
            $labels === [] => 'no preserve claim for this area',
            in_array('verified', $labels, true) && $checksCaughtNothing && $reasons === [] => 'claimed verified (overclaim)',
            default => 'labelled '.implode(', ', $labels),
        };

        return [$reasons, $label];
    }

    /**
     * Get the failing checks and failing test names in a set of results read
     * back from JSON.
     *
     * @param  array<mixed>  $checks
     * @return array{checks: list<string>, tests: list<string>}
     */
    protected function failing(array $checks): array
    {
        $names = [];
        $tests = [];

        foreach ($checks as $check) {
            if (! is_array($check) || in_array($check['outcome'] ?? 'passed', ['passed', 'not_applicable', 'skipped'], true)) {
                continue;
            }

            $names[] = is_string($check['name'] ?? null) ? $check['name'] : '(unnamed)';
            $failed = is_array($check['failed_tests'] ?? null)
                ? array_filter($check['failed_tests'], 'is_string')
                : Evidence::failedTests(is_string($check['output'] ?? null) ? $check['output'] : '');
            $tests = [...$tests, ...array_values($failed)];
        }

        return ['checks' => $names, 'tests' => array_values(array_unique($tests))];
    }

    /**
     * Describe a review's findings on one line.
     *
     * @param  array<string, mixed>  $result
     */
    protected function findings(array $result): string
    {
        $findings = is_array($result['review']['findings'] ?? null) ? $result['review']['findings'] : [];

        return $findings === [] ? '' : ' Findings: '.implode(' / ', array_map(fn (array $finding) => "({$finding['severity']}) {$finding['summary']}", $findings));
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
        $assumptions = array_map(fn (array $assumption) => $assumption['text'], is_array($run['plan']['assumptions'] ?? null) ? $run['plan']['assumptions'] : []);

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
