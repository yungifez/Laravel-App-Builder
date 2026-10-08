<?php

namespace App\Evaluation;

use App\Features\TestResults;
use App\Workspaces\CommandResult;
use Illuminate\Support\Str;

/**
 * Collects evidence from a workbench: the project's own checks, as CI would
 * run them, and the suite's hidden tests.
 */
class Evidence
{
    /**
     * Characters of output kept per result.
     */
    protected const OUTPUT_TAIL = 4000;

    /**
     * Run the project's checks (the verification checks in config/builder.php).
     *
     * @return list<array{name: string, outcome: string, exit_code: int, output: string, failed_tests: list<string>}>
     */
    public static function checks(Workbench $workbench): array
    {
        /** @var list<array{name: string, command: list<string>, timeout: int}> $checks */
        $checks = config('builder.verification.checks', []);

        return array_map(
            fn (array $check) => self::result($check['name'], $workbench->run($check['command'], $check['timeout'])),
            $checks,
        );
    }

    /**
     * Copy in the task's hidden tests (and its guards) with their own runner
     * configuration, run them, and remove them again.
     *
     * @return array{name: string, outcome: string, exit_code: int, output: string, failed_tests: list<string>, tests: int|null, failures: int|null}
     */
    public static function hidden(Workbench $workbench, Suite $suite, string $task): array
    {
        return self::tests($workbench, $suite->hiddenFiles($task));
    }

    /**
     * Copy in the given test files (keyed by their path inside tests/Hidden,
     * with their phpunit.xml), run them, and remove them again.
     *
     * @param  array<string, string>  $files
     * @return array{name: string, outcome: string, exit_code: int, output: string, failed_tests: list<string>, tests: int|null, failures: int|null}
     */
    public static function tests(Workbench $workbench, array $files): array
    {
        $workbench->run(['rm', '-rf', 'tests/Hidden'], 30);

        foreach ($files as $path => $contents) {
            $workbench->write("tests/Hidden/{$path}", $contents);
        }

        $result = $workbench->run(['php', 'vendor/bin/phpunit', '--configuration', 'tests/Hidden/phpunit.xml'], 600);
        $workbench->run(['rm', '-rf', 'tests/Hidden'], 30);

        return [...self::result('Hidden tests', $result), ...self::counts($result->output)];
    }

    /**
     * Present check results the way platform verification records them, so
     * every verification condition reviews the same evidence.
     *
     * @param  list<array{name: string, outcome: string, exit_code: int, output: string, failed_tests: list<string>}>  $checks
     * @return list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string}>
     */
    public static function asVerificationResults(array $checks): array
    {
        return array_map(fn (array $check) => [
            'name' => $check['name'],
            'stage' => 'checks',
            'outcome' => $check['outcome'],
            'exit_code' => $check['exit_code'],
            'timed_out' => $check['outcome'] === 'errored',
            'duration_ms' => 0,
            'output' => $check['output'],
        ], $checks);
    }

    /**
     * Read the test and failure counts from PHPUnit's summary line.
     *
     * @return array{tests: int|null, failures: int|null}
     */
    public static function counts(string $output): array
    {
        if (preg_match('/OK \((\d+) tests?/', $output, $ok) === 1) {
            return ['tests' => (int) $ok[1], 'failures' => 0];
        }

        if (preg_match('/^Tests: (\d+),.*$/m', $output, $summary) === 1) {
            preg_match('/Errors: (\d+)/', $summary[0], $errors);
            preg_match('/Failures: (\d+)/', $summary[0], $failures);

            return ['tests' => (int) $summary[1], 'failures' => (int) ($errors[1] ?? 0) + (int) ($failures[1] ?? 0)];
        }

        return ['tests' => null, 'failures' => null];
    }

    /**
     * Get the names of the failing tests in a test run's output, as the
     * test runner prints them.
     *
     * @return list<string>
     */
    public static function failedTests(string $output): array
    {
        return TestResults::failing($output);
    }

    /**
     * Summarise a command as a result. Failing test names are read from the
     * whole output, before it is cut.
     *
     * @return array{name: string, outcome: string, exit_code: int, output: string, failed_tests: list<string>}
     */
    protected static function result(string $name, CommandResult $result): array
    {
        $output = trim((string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $result->output."\n".$result->errorOutput));

        return [
            'name' => $name,
            'outcome' => match (true) {
                $result->timedOut => 'errored',
                $result->exitCode === 0 => 'passed',
                default => 'failed',
            },
            'exit_code' => $result->exitCode,
            'output' => mb_strlen($output) > self::OUTPUT_TAIL ? '…'.Str::substr($output, -self::OUTPUT_TAIL) : $output,
            'failed_tests' => self::failedTests($output),
        ];
    }
}
