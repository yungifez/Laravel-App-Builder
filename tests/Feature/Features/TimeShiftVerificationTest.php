<?php

namespace Tests\Feature\Features;

use App\Actions\Features\RequestVerification;
use App\Enums\VerificationStatus;
use App\Features\TimeShifts;
use App\Models\FeatureRequest;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class TimeShiftVerificationTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected const RUN = ['sh', '-c', 'run the tests at a moment', 'sh'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300]],
            'builder.verification.security.enabled' => false,
            'builder.verification.screens.enabled' => false,
            'builder.verification.change_evidence.enabled' => false,
            'builder.verification.access.enabled' => false,
            'builder.verification.time' => [
                'enabled' => true,
                'bootstrap' => 'time.php',
                'command' => self::RUN,
                'timeout' => 60,
                'report' => 'time.xml',
            ],
        ]);
    }

    /**
     * A change that adds a renewal date a month from now, and a test of it.
     */
    protected function change(string $code = '        return now()->addMonth();'): FeatureRequest
    {
        return FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Support/Renewal.php b/app/Support/Renewal.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/app/Support/Renewal.php',
            '@@ -0,0 +1,2 @@',
            '+<?php',
            "+{$code}",
            'diff --git a/tests/Unit/RenewalTest.php b/tests/Unit/RenewalTest.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/tests/Unit/RenewalTest.php',
            '@@ -0,0 +1 @@',
            '+<?php',
            '',
        ])]);
    }

    /**
     * Answer each run with the renewal test passing, except at the moments
     * given.
     *
     * @param  list<string>  $failsAt
     */
    protected function answer(array $failsAt): void
    {
        $this->driver->onExec = function (string $workspace, array $command) use ($failsAt) {
            if (array_slice($command, 0, 4) === self::RUN) {
                $failed = in_array($command[4], $failsAt, true) ? '<failure>Failed asserting that 3 is identical to 2.</failure>' : '';
                $this->driver->files["{$workspace}:time.xml"] = "<testsuites><testsuite name=\"RenewalTest\" file=\"/app/tests/Unit/RenewalTest.php\"><testcase name=\"test_renewal_is_due_next_month\" file=\"/app/tests/Unit/RenewalTest.php\">{$failed}</testcase></testsuite></testsuites>";
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
    }

    public function test_a_test_that_fails_on_the_31st_sends_the_change_back_and_says_why(): void
    {
        $this->answer([TimeShifts::MOMENTS['the 31st of a month']]);
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $result = collect($verification->results)->firstWhere('name', 'Dates at the edges');
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $this->assertSame(['checks', 'failed'], [$result['stage'], $result['outcome']]);
        $this->assertStringStartsWith('test_renewal_is_due_next_month (tests/Unit/RenewalTest.php) passes on an ordinary day but fails at the 31st of a month', $result['output']);

        // The change's test ran at each moment, once more where it failed,
        // and the extension was taken out after.
        $runs = array_values(array_filter(array_column($this->driver->executed, 'command'), fn (array $command) => array_slice($command, 0, 4) === self::RUN));
        $this->assertSame([TimeShifts::CONTROL, ...array_values(TimeShifts::MOMENTS), TimeShifts::MOMENTS['the 31st of a month']], array_column($runs, 4));
        $this->assertSame([...self::RUN, TimeShifts::CONTROL, 'time.xml', 'tests/Unit/RenewalTest.php'], $runs[0]);
        $this->assertContains(['rm', '-f', 'time.php'], array_column($this->driver->executed, 'command'));
    }

    public function test_tests_that_pass_at_every_moment_pass_the_check(): void
    {
        $this->answer([]);
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $this->assertSame('passed', collect($change->verifications()->sole()->results)->firstWhere('name', 'Dates at the edges')['outcome']);
    }

    public function test_a_change_without_date_code_runs_nothing(): void
    {
        $this->answer([TimeShifts::MOMENTS['the 31st of a month']]);
        $change = $this->change('        return $this->title;');

        app(RequestVerification::class)->handle($change);

        $this->assertNull(collect($change->verifications()->sole()->results)->firstWhere('name', 'Dates at the edges'));
        $this->assertNotContains(self::RUN, array_map(fn (array $command) => array_slice($command, 0, 4), array_column($this->driver->executed, 'command')));
    }
}
