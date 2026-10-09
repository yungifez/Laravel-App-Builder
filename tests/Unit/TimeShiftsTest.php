<?php

namespace Tests\Unit;

use App\Features\TestReport;
use App\Features\TimeShifts;
use Tests\TestCase;

class TimeShiftsTest extends TestCase
{
    /**
     * A change to one PHP file with the given added line.
     */
    protected function diff(string $path, string $line): string
    {
        return implode("\n", [
            "diff --git a/{$path} b/{$path}",
            "--- a/{$path}",
            "+++ b/{$path}",
            '@@ -1 +1,2 @@',
            ' <?php',
            "+{$line}",
            '',
        ]);
    }

    public function test_only_added_app_code_that_works_with_dates_is_worth_the_runs(): void
    {
        $this->assertTrue(TimeShifts::touchesDates($this->diff('app/Support/Renewal.php', '        return now()->addMonth();')));
        $this->assertTrue(TimeShifts::touchesDates($this->diff('app/Models/Booking.php', '        return $this->starts_at->endOfDay();')));
        $this->assertFalse(TimeShifts::touchesDates($this->diff('app/Models/Booking.php', '        return $this->title;')));
        $this->assertFalse(TimeShifts::touchesDates($this->diff('tests/Unit/RenewalTest.php', '        $this->travelTo(now()->addMonth());')));
        $this->assertFalse(TimeShifts::touchesDates($this->diff('resources/js/app.ts', 'const due = now().addMonth();')));
        $this->assertFalse(TimeShifts::touchesDates(null));
    }

    public function test_the_bootstrap_is_plain_php_that_loads_the_app_and_reads_the_moment(): void
    {
        $bootstrap = TimeShifts::bootstrap();

        $this->assertNotFalse(token_get_all($bootstrap, TOKEN_PARSE));
        $this->assertStringContainsString("require getcwd().'/vendor/autoload.php';", $bootstrap);
        $this->assertStringContainsString("getenv('TIME_SHIFT_TO')", $bootstrap);
        $this->assertStringContainsString('final class TimeShiftExtension implements PHPUnit\Runner\Extension\Extension', $bootstrap);
    }

    public function test_a_test_that_passed_on_the_ordinary_day_and_failed_at_a_moment_twice_is_a_finding(): void
    {
        $test = fn (string $name, string $outcome) => ['file' => '/app/tests/Unit/RenewalTest.php', 'name' => $name, 'outcome' => $outcome];
        $control = [$test('test_due_next_month', TestReport::PASSED), $test('test_frozen_clock', TestReport::FAILED), $test('test_flaky', TestReport::PASSED)];

        $findings = TimeShifts::failing($control, [
            'the last second of a year' => [$test('test_due_next_month', TestReport::PASSED), $test('test_frozen_clock', TestReport::FAILED)],
            'the 31st of a month' => [$test('test_due_next_month', TestReport::FAILED), $test('test_flaky', TestReport::FAILED)],
            'a leap day' => [$test('test_due_next_month', TestReport::FAILED)],
        ]);

        // Failing on the ordinary day already is the stopped clock's fault;
        // one moment per test is enough.
        $this->assertSame(['test_due_next_month the 31st of a month', 'test_flaky the 31st of a month'], array_map(fn (array $finding) => "{$finding['name']} {$finding['moment']}", $findings));
        $this->assertSame('2027-01-31 12:00:00', $findings[0]['at']);

        $confirmed = TimeShifts::confirmed($findings, ['the 31st of a month' => [$test('test_due_next_month', TestReport::FAILED), $test('test_flaky', TestReport::PASSED)]]);

        $this->assertSame(['test_due_next_month'], array_column($confirmed, 'name'));
        $this->assertSame(implode("\n", [
            'test_due_next_month (tests/Unit/RenewalTest.php) passes on an ordinary day but fails at the 31st of a month (2027-01-31 12:00:00 UTC), twice. Check how the change counts seconds, days, months or years there: for example addMonth() on the 31st, or a day that ends before the work does.',
            "Ran the change's 3 tests on an ordinary day and at the last second of a year, the 31st of a month, a leap day, the end of February.",
        ]), TimeShifts::describe($confirmed, 3));
    }
}
