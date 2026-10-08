<?php

namespace App\Console\Commands;

use App\Evaluation\Evidence;
use App\Evaluation\Results;
use App\Evaluation\Suite;
use App\Evaluation\Workbench;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('eval:validate')]
#[Description('Check that an evaluation suite\'s cases are valid before it is frozen: tests fail without the change and pass with the reference, and each sabotage applies and is caught as declared')]
class ValidateEvaluation extends Command
{
    /**
     * @var list<array{case: string, check: string, ok: bool, detail: string}>
     */
    protected array $rows = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $suite = Suite::fromConfig();

        // The untouched project: new behaviour is missing, guards hold.
        $base = Workbench::create('validate-base');

        try {
            foreach ($suite->taskKeys() as $task) {
                $definition = $suite->task($task);
                $own = Evidence::tests($base, $this->only($suite, $definition['hidden']));
                $guardFiles = $definition['guards'] ?? [];
                $guards = $guardFiles === [] ? null : Evidence::tests($base, $this->only($suite, $guardFiles));

                $this->row($task, 'its tests fail without the change', $own['outcome'] !== 'passed', $this->counts($own));
                $this->row($task, 'its guards pass without the change', $guards === null || $guards['outcome'] === 'passed', $guards === null ? 'no guards' : $this->counts($guards));
            }
        } finally {
            $base->destroy();
        }

        // Each reference solution: everything passes, and every sabotage still applies on top.
        foreach ($suite->taskKeys() as $task) {
            $reference = $suite->reference($task);

            if ($reference === null) {
                $this->row($task, 'has a reference solution', false, 'none');

                continue;
            }

            $workbench = Workbench::create('validate-'.substr(md5($task), 0, 10));

            try {
                $applied = $workbench->apply($reference)->successful();
                $this->row($task, 'reference applies', $applied, '');

                if (! $applied) {
                    continue;
                }

                $hidden = Evidence::hidden($workbench, $suite, $task);
                $failing = array_column(array_filter(Evidence::checks($workbench), fn (array $check) => $check['outcome'] !== 'passed'), 'name');

                $this->row($task, 'reference passes its tests and guards', $hidden['outcome'] === 'passed', $this->counts($hidden));
                $this->row($task, 'reference passes the project\'s checks', $failing === [], $failing === [] ? 'all passed' : 'failing: '.implode(', ', $failing));

                foreach ($suite->sabotage() as $sabotage) {
                    $file = '.git/validate-sabotage.patch';
                    $workbench->write($file, $suite->sabotagePatch($sabotage['patch']));
                    $this->row($task, "{$sabotage['key']} applies on the reference", $workbench->run(['git', 'apply', '--check', $file], 60)->successful(), '');
                }
            } finally {
                $workbench->destroy();
            }
        }

        // Each sabotage on the untouched project: caught by the project's tests, or only by its guards.
        foreach ($suite->sabotage() as $sabotage) {
            $workbench = Workbench::create('validate-'.substr(md5($sabotage['key']), 0, 10));

            try {
                $applied = $workbench->apply($suite->sabotagePatch($sabotage['patch']), 'sabotage')->successful();
                $this->row($sabotage['key'], 'applies to the untouched project', $applied, '');

                if (! $applied) {
                    continue;
                }

                $failing = array_column(array_filter(Evidence::checks($workbench), fn (array $check) => $check['outcome'] !== 'passed'), 'name');
                $guards = Evidence::tests($workbench, $this->only($suite, $sabotage['caught_by'] ?? []));

                if ($sabotage['covered_by_tests']) {
                    $this->row($sabotage['key'], 'the project\'s tests catch it', in_array('Tests', $failing, true), $failing === [] ? 'all checks passed' : 'failing: '.implode(', ', $failing));
                } else {
                    $this->row($sabotage['key'], 'the project\'s checks all pass', $failing === [], $failing === [] ? 'all passed' : 'failing: '.implode(', ', $failing));
                }

                $this->row($sabotage['key'], 'a guard in caught_by catches it', $guards['outcome'] !== 'passed', $this->counts($guards));
            } finally {
                $workbench->destroy();
            }
        }

        $valid = array_filter($this->rows, fn (array $row) => ! $row['ok']) === [];
        $lines = ['# Case validation', '', $valid ? 'All cases are valid.' : 'Some cases are not valid.', '', '| Case | Check | OK | Detail |', '| --- | --- | --- | --- |'];

        foreach ($this->rows as $row) {
            $lines[] = "| {$row['case']} | {$row['check']} | ".($row['ok'] ? 'yes' : '**no**')." | {$row['detail']} |";
        }

        Results::fromConfig()->put('validation.md', implode("\n", $lines)."\n");
        $this->table(['Case', 'Check', 'OK', 'Detail'], array_map(fn (array $row) => [$row['case'], $row['check'], $row['ok'] ? 'yes' : 'NO', $row['detail']], $this->rows));

        return $valid ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Get the runner configuration and support files with only these tests.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    protected function only(Suite $suite, array $paths): array
    {
        $files = ['phpunit.xml' => File::get("{$suite->directory}/hidden/phpunit.xml")];

        foreach (File::allFiles("{$suite->directory}/hidden/Support") as $file) {
            $files['Support/'.$file->getRelativePathname()] = $file->getContents();
        }

        foreach ($paths as $path) {
            $files[$path] = File::get("{$suite->directory}/hidden/{$path}");
        }

        return $files;
    }

    /**
     * @param  array{outcome: string, tests: int|null, failures: int|null}  $result
     */
    protected function counts(array $result): string
    {
        return "{$result['outcome']} (".($result['tests'] ?? '?').' tests, '.($result['failures'] ?? '?').' failing)';
    }

    protected function row(string $case, string $check, bool $ok, string $detail): void
    {
        $this->rows[] = ['case' => $case, 'check' => $check, 'ok' => $ok, 'detail' => $detail];
    }
}
