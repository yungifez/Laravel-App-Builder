<?php

namespace App\Console\Commands;

use App\Evaluation\Results;
use App\Evaluation\Suite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('eval:compare')]
#[Description('Score the verification conditions on every frozen snapshot: detection, misses, false positives, evidence accuracy, cost and intervention')]
class CompareEvaluation extends Command
{
    /**
     * The verification conditions, in order.
     *
     * @var array<string, string>
     */
    protected const CONDITIONS = ['v1' => 'Tests only', 'v2' => 'Tests + generic review', 'v3' => 'Tests + behaviour-aware review'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $suite = Suite::fromConfig();
        $results = Results::fromConfig();
        /** @var array<string, array<string, array<string, int>>> $tally */
        $tally = [];
        $evidence = ['clean_snapshots' => 0, 'clean_flags' => 0, 'sabotage_snapshots' => 0, 'same_area_flags' => 0, 'other_area_flags' => 0, 'legacy_labels' => 0];
        $rows = [];

        foreach ($suite->taskKeys() as $task) {
            $category = $suite->task($task)['category'] ?? 'unspecified';

            foreach (['pipeline', 'plain'] as $arm) {
                $natural = $results->json("{$task}/{$arm}/conditions/natural.json");

                if ($natural === null || ($natural['applicable'] ?? false) !== true) {
                    continue;
                }

                $defective = (bool) ($natural['ground_truth']['defective'] ?? false);

                foreach (self::CONDITIONS as $condition => $name) {
                    $flagged = (bool) ($natural[$condition]['flagged'] ?? false);
                    $key = match (true) {
                        $defective && $flagged => 'caught',
                        $defective => 'missed',
                        $flagged => 'false_positive',
                        default => 'quiet',
                    };
                    $this->bump($tally, $condition, 'natural', $key);

                    if ($category === 'authorized-change' && ! $defective) {
                        $this->bump($tally, $condition, 'authorized', $flagged ? 'rejected' : 'accepted');
                    }
                }

                $rows[] = "| {$task} | {$category} | {$arm} | natural | ".($defective ? 'defective' : 'clean').' | '.$this->marks($natural).' |';
                $this->countEvidence($evidence, $natural, null);

                foreach ($suite->sabotage() as $sabotage) {
                    $snapshot = $results->json("{$task}/{$arm}/conditions/{$sabotage['key']}.json");

                    if ($snapshot === null || ($snapshot['applicable'] ?? false) !== true) {
                        continue;
                    }

                    $coverage = $sabotage['covered_by_tests'] ? 'covered' : 'uncovered';

                    foreach (array_keys(self::CONDITIONS) as $condition) {
                        $caught = $this->attributable($condition, $snapshot, $natural);
                        $this->bump($tally, $condition, "sabotage_{$coverage}", $caught ? 'caught' : 'missed');
                    }

                    $rows[] = "| {$task} | {$category} | {$arm} | {$sabotage['key']} ({$coverage}) | sabotaged | ".$this->marks($snapshot, $natural).' |';
                    $this->countEvidence($evidence, $snapshot, $sabotage['area']);
                }
            }
        }

        $results->put('comparison.md', implode("\n", [
            '# Comparison',
            '',
            'Each condition judged the same frozen snapshots with the same check results.',
            '',
            '## Detection',
            '',
            '| Condition | Changes as made: caught / missed / false alarm / quiet | Authorized changes: accepted / rejected | Covered sabotage caught | Uncovered sabotage caught |',
            '| --- | --- | --- | --- | --- |',
            ...array_map(fn (string $condition) => $this->tallyRow($tally[$condition] ?? [], self::CONDITIONS[$condition]), array_keys(self::CONDITIONS)),
            '',
            'Sabotage counts as caught only when attributable to it: tests that newly fail compared with the change as made, a blocking finding naming a file the sabotage touched, the condition objecting where it did not on the change as made, or (behaviour-aware) its area newly flagged.',
            '',
            '## Behaviour-aware evidence labels',
            '',
            "- Flags on clean snapshots (false alarms): {$evidence['clean_flags']} across {$evidence['clean_snapshots']} snapshots.",
            "- On sabotaged snapshots: {$evidence['same_area_flags']} flags in the sabotaged area, {$evidence['other_area_flags']} in other areas, across {$evidence['sabotage_snapshots']} snapshots.",
            '- Unrelated flags per genuine regression: '.($evidence['sabotage_snapshots'] === 0 ? 'n/a' : round(($evidence['other_area_flags'] + $evidence['clean_flags']) / $evidence['sabotage_snapshots'], 2)).' (other-area flags on sabotaged snapshots plus all flags on clean ones, per sabotaged snapshot). Flags inside the sabotaged area can still be unrelated; that needs judging by hand.',
            "- Labels claiming a behaviour held (legacy \"verified\"): {$evidence['legacy_labels']}.",
            '',
            '## Snapshots',
            '',
            '| Task | Category | Arm | Snapshot | Ground truth | v1 / v2 / v3 |',
            '| --- | --- | --- | --- | --- | --- |',
            ...$rows,
            '',
            ...$this->costAndIntervention($suite, $results),
        ])."\n");

        $this->info('Comparison: '.$results->path('comparison.md'));

        return self::SUCCESS;
    }

    /**
     * Count one outcome for a condition.
     *
     * @param  array<string, array<string, array<string, int>>>  $tally
     */
    protected function bump(array &$tally, string $condition, string $group, string $key): void
    {
        $tally[$condition][$group][$key] = ($tally[$condition][$group][$key] ?? 0) + 1;
    }

    /**
     * Determine if a condition caught a sabotage, attributably.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $natural
     */
    protected function attributable(string $condition, array $snapshot, array $natural): bool
    {
        $now = is_array($snapshot[$condition] ?? null) ? $snapshot[$condition] : [];
        $before = is_array($natural[$condition] ?? null) ? $natural[$condition] : [];

        if (($now['flagged'] ?? false) === true && ($before['flagged'] ?? false) !== true) {
            return true;
        }

        if ($condition === 'v1') {
            return array_diff($this->strings($now['failed_tests'] ?? []), $this->strings($before['failed_tests'] ?? [])) !== []
                || array_diff($this->strings($now['failing_checks'] ?? []), $this->strings($before['failing_checks'] ?? [])) !== [];
        }

        $files = $this->strings($snapshot['ground_truth']['sabotage_files'] ?? []);
        $review = is_array($now['review'] ?? null) ? $now['review'] : [];

        foreach (is_array($review['findings'] ?? null) ? $review['findings'] : [] as $finding) {
            if (is_array($finding) && ($finding['severity'] ?? null) === 'blocking' && is_string($finding['file'] ?? null)) {
                foreach ($files as $file) {
                    if (str_ends_with($finding['file'], $file) || str_ends_with($file, $finding['file'])) {
                        return true;
                    }
                }
            }
        }

        if ($condition === 'v3') {
            $area = $snapshot['ground_truth']['sabotage_area'] ?? null;

            return $this->flaggedAreas($review) !== [] && in_array($area, array_diff($this->flaggedAreas($review), $this->flaggedAreas(is_array($before['review'] ?? null) ? $before['review'] : [])), true);
        }

        return false;
    }

    /**
     * Count the behaviour-aware labels that flag a problem, by where they are.
     *
     * @param  array<string, int>  $evidence
     * @param  array<string, mixed>  $snapshot
     */
    protected function countEvidence(array &$evidence, array $snapshot, ?string $sabotageArea): void
    {
        $review = is_array($snapshot['v3']['review'] ?? null) ? $snapshot['v3']['review'] : [];
        $items = is_array($review['preserved'] ?? null) ? $review['preserved'] : [];
        $defective = (bool) ($snapshot['ground_truth']['defective'] ?? false);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['evidence'] ?? null) === 'verified') {
                $evidence['legacy_labels']++;
            }

            if (! in_array($item['evidence'] ?? null, ['regression_suspected', 'tests_failed'], true)) {
                continue;
            }

            if ($sabotageArea !== null) {
                $evidence[($item['area'] ?? null) === $sabotageArea ? 'same_area_flags' : 'other_area_flags']++;
            } elseif (! $defective) {
                $evidence['clean_flags']++;
            }
        }

        if ($sabotageArea !== null) {
            $evidence['sabotage_snapshots']++;
        } elseif (! $defective) {
            $evidence['clean_snapshots']++;
        }
    }

    /**
     * @param  array<string, mixed>  $review
     * @return list<string>
     */
    protected function flaggedAreas(array $review): array
    {
        $areas = [];

        foreach (is_array($review['preserved'] ?? null) ? $review['preserved'] : [] as $item) {
            if (is_array($item) && in_array($item['evidence'] ?? null, ['regression_suspected', 'tests_failed'], true) && is_string($item['area'] ?? null)) {
                $areas[] = $item['area'];
            }
        }

        return array_values(array_unique($areas));
    }

    /**
     * Mark each condition's verdict on a snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $natural
     */
    protected function marks(array $snapshot, ?array $natural = null): string
    {
        return implode(' / ', array_map(function (string $condition) use ($snapshot, $natural) {
            if ($natural !== null) {
                return $this->attributable($condition, $snapshot, $natural) ? 'caught' : 'missed';
            }

            return (bool) ($snapshot[$condition]['flagged'] ?? false) ? 'flagged' : 'quiet';
        }, array_keys(self::CONDITIONS)));
    }

    /**
     * @param  array<string, array<string, int>>  $tally
     */
    protected function tallyRow(array $tally, string $name): string
    {
        $count = fn (string $group, string $key) => $tally[$group][$key] ?? 0;
        $ratio = fn (string $group) => $count($group, 'caught').'/'.($count($group, 'caught') + $count($group, 'missed'));

        return "| {$name} | {$count('natural', 'caught')} / {$count('natural', 'missed')} / {$count('natural', 'false_positive')} / {$count('natural', 'quiet')} | {$count('authorized', 'accepted')} / {$count('authorized', 'rejected')} | {$ratio('sabotage_covered')} | {$ratio('sabotage_uncovered')} |";
    }

    /**
     * Summarise model usage per role and condition from the ledger, and the
     * runs' need for a person.
     *
     * @return list<string>
     */
    protected function costAndIntervention(Suite $suite, Results $results): array
    {
        $lines = ['## Cost', ''];
        $ledger = $results->path('usage.jsonl');
        $totals = [];

        if (File::exists($ledger)) {
            foreach (array_filter(explode("\n", File::get($ledger))) as $line) {
                $entry = json_decode($line, true);

                if (is_array($entry) && is_int($entry['tokens'] ?? null)) {
                    $key = is_string($entry['condition'] ?? null) ? "review condition {$entry['condition']}" : (is_string($entry['arm'] ?? null) ? $entry['arm'] : '?').' '.(is_string($entry['role'] ?? null) ? $entry['role'] : '?');
                    $totals[$key]['tokens'] = ($totals[$key]['tokens'] ?? 0) + $entry['tokens'];
                    $totals[$key]['calls'] = ($totals[$key]['calls'] ?? 0) + 1;
                }
            }
        }

        if ($totals === []) {
            $lines[] = 'No usage ledger (results/usage.jsonl).';
        } else {
            ksort($totals);
            array_push($lines, '| Where | Calls | Tokens |', '| --- | --- | --- |');

            foreach ($totals as $key => $total) {
                $lines[] = "| {$key} | {$total['calls']} | {$total['tokens']} |";
            }
        }

        array_push($lines, '', '## Intervention', '', '| Task | Pipeline run | Repairs | Plain agent |', '| --- | --- | --- | --- |');

        foreach ($suite->taskKeys() as $task) {
            $run = $results->json("{$task}/pipeline/run.json");
            $plain = $results->json("{$task}/plain/agent.json");
            $lines[] = '| '.$task.' | '.(is_string($run['status'] ?? null) ? $run['status'] : '—').' | '.(is_int($run['repairs'] ?? null) ? $run['repairs'] : '—').' | '.(is_string($plain['status'] ?? null) ? $plain['status'] : '—').' |';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
