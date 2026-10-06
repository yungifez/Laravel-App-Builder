<?php

namespace Tests\Feature\Features;

use App\Actions\Features\RequestVerification;
use App\Enums\VerificationStatus;
use App\Features\InputProbes;
use App\Models\FeatureRequest;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class InputProbeVerificationTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected const ROUTES = ['sh', '-c', 'list the routes'];

    protected const INPUTS = ['sh', '-c', 'run a probe test', 'sh'];

    protected const RULES = '{"id":0,"status":302,"source":"app/Http/Requests/StoreBookingRequest.php","fields":{"title":["required","string","max:20"],"nickname":["nullable","string","max:30"]},"reason":null}';

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
            'builder.verification.time.enabled' => false,
            'builder.verification.access.enabled' => false,
            'builder.verification.replay.enabled' => false,
            'builder.verification.access.routes' => ['command' => self::ROUTES, 'report' => 'routes.json'],
            'builder.verification.inputs' => [
                'enabled' => true,
                'probes' => 40,
                'rules_test' => 'tests/Feature/InputRulesProbeTest.php',
                'test' => 'tests/Feature/InputProbeTest.php',
                'command' => self::INPUTS,
                'timeout' => 60,
                'rules_report' => 'input-rules.jsonl',
                'report' => 'inputs.jsonl',
            ],
        ]);
    }

    /**
     * A change to the controller that adds bookings, and, when asked, the
     * new form request that holds its rules.
     */
    protected function change(bool $newRules): FeatureRequest
    {
        $file = fn (string $path, string $header) => [
            "diff --git a/{$path} b/{$path}",
            ...($header === '' ? [] : [$header]),
            $header === '' ? "--- a/{$path}" : '--- /dev/null',
            "+++ b/{$path}",
            $header === '' ? '@@ -1 +1,2 @@' : '@@ -0,0 +1 @@',
            ...($header === '' ? [' <?php'] : []),
            '+// Bookings',
        ];

        return FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            ...$file('app/Http/Controllers/BookingController.php', ''),
            ...($newRules ? $file('app/Http/Requests/StoreBookingRequest.php', 'new file mode 100644') : []),
            '',
        ])]);
    }

    /**
     * Answer the route list with the form that adds a booking, the rules
     * test with its rules, and the probe test with the whole form as
     * given and each probe as it should end, except those named.
     *
     * @param  array<string, array<string, mixed>>  $answers  By what the probe says
     * @param  array<string, mixed>  $form
     */
    protected function answer(array $answers, array $form = ['status' => 302, 'errors' => []]): void
    {
        $routes = [['method' => 'POST', 'uri' => 'bookings', 'action' => 'App\Http\Controllers\BookingController@store']];
        $planned = InputProbes::plan($routes, InputProbes::rules(self::RULES), 40);

        $this->driver->onExec = function (string $workspace, array $command) use ($answers, $form, $planned) {
            if ($command === self::ROUTES) {
                $this->driver->files["{$workspace}:routes.json"] = (string) json_encode([
                    ['domain' => null, 'method' => 'POST', 'uri' => 'bookings', 'name' => 'bookings.store', 'action' => 'App\Http\Controllers\BookingController@store', 'middleware' => ['web']],
                ]);
            }

            if ($command === [...self::INPUTS, 'tests/Feature/InputRulesProbeTest.php', 'input-rules.jsonl']) {
                $this->driver->files["{$workspace}:input-rules.jsonl"] = self::RULES;
            }

            if ($command === [...self::INPUTS, 'tests/Feature/InputProbeTest.php', 'inputs.jsonl']) {
                $lines = [json_encode(['kind' => 'form', 'id' => 0, 'exception' => null, 'reason' => null, ...$form])];

                foreach ($planned['probes'] as $id => $probe) {
                    $lines[] = json_encode(['kind' => 'probe', 'id' => $id, 'exception' => null, 'reason' => null, ...$answers[$probe['says']] ?? ['status' => 302, 'errors' => $probe['expect'] === 'refuse' ? [$probe['field']] : []]]);
                }

                $this->driver->files["{$workspace}:inputs.jsonl"] = implode("\n", $lines);
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
    }

    public function test_a_new_form_that_breaks_on_a_left_out_field_sends_the_change_back(): void
    {
        $this->answer(['nickname left out' => ['status' => 500, 'errors' => [], 'exception' => 'ErrorException']]);
        $change = $this->change(newRules: true);

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $result = collect($verification->results)->firstWhere('name', 'Forms turn down wrong values');
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $this->assertSame(['checks', 'failed'], [$result['stage'], $result['outcome']]);
        $this->assertStringStartsWith('POST /bookings broke (ErrorException) on nickname left out. It should answer with a message.', $result['output']);

        $commands = array_column($this->driver->executed, 'command');
        $this->assertContains([...self::INPUTS, 'tests/Feature/InputRulesProbeTest.php', 'input-rules.jsonl'], $commands);
        $this->assertContains(['rm', '-f', 'tests/Feature/InputRulesProbeTest.php'], $commands);
        $this->assertContains(['rm', '-f', 'tests/Feature/InputProbeTest.php'], $commands);
    }

    public function test_a_wrong_value_the_app_took_before_the_change_is_reported_but_not_sent_back(): void
    {
        $this->answer(['title 21 characters long (max:20)' => ['status' => 302, 'errors' => []]]);
        $change = $this->change(newRules: false);

        app(RequestVerification::class)->handle($change);

        $result = collect($change->verifications()->sole()->results)->firstWhere('name', 'Forms turn down wrong values');
        $this->assertSame('passed', $result['outcome']);
        $this->assertStringStartsWith("Already so before this change, so not sent back:\n- POST /bookings accepted title 21 characters long (max:20).", $result['output']);
    }

    public function test_a_whole_form_the_app_turned_down_adds_no_check(): void
    {
        $this->answer(['nickname left out' => ['status' => 500, 'errors' => [], 'exception' => 'ErrorException']], ['status' => 302, 'errors' => ['title']]);
        $change = $this->change(newRules: true);

        app(RequestVerification::class)->handle($change);

        $this->assertNull(collect($change->verifications()->sole()->results)->firstWhere('name', 'Forms turn down wrong values'));
    }
}
