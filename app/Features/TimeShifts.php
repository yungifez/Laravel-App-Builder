<?php

namespace App\Features;

/**
 * Run the change's own tests at moments where date code often breaks
 * (direction 32, the time engine): the last second of a year, the 31st of
 * a month, a leap day and the end of February. A PHPUnit extension sets
 * Carbon's clock before each test, so the app needs no change; a test
 * that sets its own time still wins.
 *
 * The same tests also run frozen on an ordinary day first. Only a test
 * that passes there and fails at a moment, twice, is a finding: one that
 * fails on the ordinary day is broken by a stopped clock, not by a date.
 *
 * @phpstan-type Finding array{file: string, name: string, moment: string, at: string}
 */
class TimeShifts
{
    /**
     * The ordinary day every test is first run on.
     */
    public const CONTROL = '2027-06-15 10:00:00';

    /**
     * The moments tried, by what the owner and the agent are told.
     */
    public const MOMENTS = [
        'the last second of a year' => '2027-12-31 23:59:59',
        'the 31st of a month' => '2027-01-31 12:00:00',
        'a leap day' => '2028-02-29 12:00:00',
        'the end of February' => '2027-02-28 23:59:59',
    ];

    /**
     * The name of the environment variable the extension reads.
     */
    public const VARIABLE = 'TIME_SHIFT_TO';

    /**
     * Added lines that work with dates or times.
     */
    protected const DATE_CODE = '/\b(now|today|tomorrow|yesterday)\(|\bCarbon(Immutable)?\b|\bDate::|->(add|sub)(Second|Minute|Hour|Day|Weekday|Week|Month|Year)s?(NoOverflow)?\(|->(startOf|endOf)[A-Z]\w*\(|->diffIn\w+\(|\bstrtotime\(|\bdate\(|\bmktime\(/';

    /**
     * Determine if a change's added code works with dates, so it is worth
     * the runs.
     */
    public static function touchesDates(?string $patch): bool
    {
        foreach (PatchSummary::files($patch) as $file) {
            if (! str_ends_with($file['path'], '.php') || str_starts_with($file['path'], 'tests/')) {
                continue;
            }

            foreach (explode("\n", $file['diff']) as $line) {
                if (str_starts_with($line, '+') && ! str_starts_with($line, '+++') && preg_match(self::DATE_CODE, $line) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The file run before the tests: the app's autoloader, and the
     * extension that sets the clock from the environment before each test.
     */
    public static function bootstrap(): string
    {
        $variable = var_export(self::VARIABLE, true);

        return <<<PHP
<?php

require getcwd().'/vendor/autoload.php';

final class TimeShiftExtension implements PHPUnit\\Runner\\Extension\\Extension
{
    public function bootstrap(PHPUnit\\TextUI\\Configuration\\Configuration \$configuration, PHPUnit\\Runner\\Extension\\Facade \$facade, PHPUnit\\Runner\\Extension\\ParameterCollection \$parameters): void
    {
        \$moment = getenv({$variable});

        if (! is_string(\$moment) || \$moment === '') {
            return;
        }

        \$facade->registerSubscriber(new class(\$moment) implements PHPUnit\\Event\\Test\\PreparationStartedSubscriber
        {
            public function __construct(private string \$moment) {}

            public function notify(PHPUnit\\Event\\Test\\PreparationStarted \$event): void
            {
                Carbon\\Carbon::setTestNow(Carbon\\Carbon::parse(\$this->moment, 'UTC'));
            }
        });
    }
}

PHP;
    }

    /**
     * Find the tests that passed on the ordinary day and failed at a
     * moment.
     *
     * @param  list<array{file: string, name: string, outcome: string}>  $control
     * @param  array<string, list<array{file: string, name: string, outcome: string}>>  $moments  By moment name
     * @return list<Finding>
     */
    public static function failing(array $control, array $moments): array
    {
        $passed = [];

        foreach ($control as $test) {
            if ($test['outcome'] === TestReport::PASSED) {
                $passed["{$test['file']}::{$test['name']}"] = true;
            }
        }

        $findings = [];

        foreach ($moments as $moment => $tests) {
            foreach ($tests as $test) {
                if ($test['outcome'] === TestReport::FAILED && isset($passed[$key = "{$test['file']}::{$test['name']}"])) {
                    $findings[] = ['file' => $test['file'], 'name' => $test['name'], 'moment' => $moment, 'at' => self::MOMENTS[$moment] ?? $moment];
                    unset($passed[$key]);
                }
            }
        }

        return $findings;
    }

    /**
     * Keep the findings whose test failed again at the same moment.
     *
     * @param  list<Finding>  $findings
     * @param  array<string, list<array{file: string, name: string, outcome: string}>>  $again  By moment name
     * @return list<Finding>
     */
    public static function confirmed(array $findings, array $again): array
    {
        return array_values(array_filter($findings, fn (array $finding) => TestReport::outcome($again[$finding['moment']] ?? [], $finding['file'], $finding['name']) === TestReport::FAILED));
    }

    /**
     * Say what the runs found, for the agent that repairs the change and
     * for the owner reading the check.
     *
     * @param  list<Finding>  $findings
     */
    public static function describe(array $findings, int $tests): string
    {
        $lines = [];

        foreach ($findings as $finding) {
            $file = ($at = strpos($finding['file'], '/tests/')) === false ? $finding['file'] : substr($finding['file'], $at + 1);
            $lines[] = "{$finding['name']} ({$file}) passes on an ordinary day but fails at {$finding['moment']} ({$finding['at']} UTC), twice. Check how the change counts seconds, days, months or years there: for example addMonth() on the 31st, or a day that ends before the work does.";
        }

        $lines[] = sprintf('Ran the change\'s %d tests on an ordinary day and at %s.', $tests, implode(', ', array_keys(self::MOMENTS)));

        return implode("\n", $lines);
    }
}
