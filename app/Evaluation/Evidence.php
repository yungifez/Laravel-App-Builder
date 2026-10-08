<?php

namespace App\Evaluation;

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
     * @return list<array{name: string, outcome: string, exit_code: int, output: string}>
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
     * Copy in the task's hidden tests with their own runner configuration,
     * run them, and remove them again.
     *
     * @return array{name: string, outcome: string, exit_code: int, output: string, tests: int|null, failures: int|null}
     */
    public static function hidden(Workbench $workbench, Suite $suite, string $task): array
    {
        $workbench->run(['rm', '-rf', 'tests/Hidden'], 30);

        foreach ($suite->hiddenFiles($task) as $path => $contents) {
            $workbench->write("tests/Hidden/{$path}", $contents);
        }

        $result = $workbench->run(['php', 'vendor/bin/phpunit', '--configuration', 'tests/Hidden/phpunit.xml'], 600);
        $workbench->run(['rm', '-rf', 'tests/Hidden'], 30);

        return [...self::result('Hidden tests', $result), ...self::counts($result->output)];
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

        if (preg_match('/Tests: (\d+).*?(?:Errors: (\d+))?.*?(?:Failures: (\d+))?\./s', $output, $failed) === 1) {
            return ['tests' => (int) $failed[1], 'failures' => (int) ($failed[2] ?? 0) + (int) ($failed[3] ?? 0)];
        }

        return ['tests' => null, 'failures' => null];
    }

    /**
     * Summarise a command as a result.
     *
     * @return array{name: string, outcome: string, exit_code: int, output: string}
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
        ];
    }
}
