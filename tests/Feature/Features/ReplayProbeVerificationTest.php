<?php

namespace Tests\Feature\Features;

use App\Actions\Features\RequestVerification;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class ReplayProbeVerificationTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected const ROUTES = ['sh', '-c', 'list the routes'];

    protected const REPLAY = ['sh', '-c', 'send the forms twice', 'sh'];

    protected const MODELS = ['find', 'app/Models', '-maxdepth', '1', '-name', '*.php', '-type', 'f'];

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
            'builder.verification.access.routes' => ['command' => self::ROUTES, 'report' => 'routes.json'],
            'builder.verification.replay' => [
                'enabled' => true,
                'probes' => 10,
                'test' => 'tests/Feature/ReplayProbeTest.php',
                'command' => self::REPLAY,
                'timeout' => 60,
                'report' => 'replay.jsonl',
            ],
        ]);
    }

    /**
     * A change to the controller that adds bookings.
     */
    protected function change(): FeatureRequest
    {
        return FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/app/Http/Controllers/BookingController.php b/app/Http/Controllers/BookingController.php',
            '--- a/app/Http/Controllers/BookingController.php',
            '+++ b/app/Http/Controllers/BookingController.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+// Bookings',
            '',
        ])]);
    }

    /**
     * Answer the route list with the route that adds a booking, the models
     * with a booking, and the probe with what came back.
     */
    protected function answer(string $line): void
    {
        $this->driver->onExec = function (string $workspace, array $command) use ($line) {
            if ($command === self::ROUTES) {
                $this->driver->files["{$workspace}:routes.json"] = (string) json_encode([
                    ['domain' => null, 'method' => 'POST', 'uri' => 'bookings', 'name' => 'bookings.store', 'action' => 'App\Http\Controllers\BookingController@store', 'middleware' => ['web']],
                ]);
            }

            if ($command === self::MODELS) {
                return new CommandResult(exitCode: 0, output: "app/Models/Booking.php\napp/Models/User.php\n", errorOutput: '', durationMs: 5);
            }

            if (array_slice($command, 0, 4) === self::REPLAY) {
                $this->driver->files["{$workspace}:replay.jsonl"] = $line;
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
    }

    public function test_a_form_that_breaks_on_the_second_send_sends_the_change_back(): void
    {
        $this->answer('{"id":0,"first":201,"added":true,"second":500,"duplicate":"bookings.room_id, bookings.starts_at"}');
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $verification = $change->verifications()->sole();
        $result = collect($verification->results)->firstWhere('name', 'Sending a form twice');
        $this->assertSame(VerificationStatus::Failed, $verification->status);
        $this->assertSame(['checks', 'failed'], [$result['stage'], $result['outcome']]);
        $this->assertStringStartsWith('Sending the form that adds a booking twice broke the page: the second POST /bookings answered 500', $result['output']);

        $commands = array_column($this->driver->executed, 'command');
        $this->assertContains([...self::REPLAY, 'tests/Feature/ReplayProbeTest.php'], $commands);
        $this->assertContains(['rm', '-f', 'tests/Feature/ReplayProbeTest.php'], $commands);
    }

    public function test_a_second_send_turned_down_with_a_message_passes(): void
    {
        $this->answer('{"id":0,"first":201,"added":true,"second":302,"duplicate":null}');
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $this->assertSame('passed', collect($change->verifications()->sole()->results)->firstWhere('name', 'Sending a form twice')['outcome']);
    }

    public function test_a_first_send_that_added_nothing_adds_no_check(): void
    {
        $this->answer('{"id":0,"first":302,"added":false,"second":0,"duplicate":null}');
        $change = $this->change();

        app(RequestVerification::class)->handle($change);

        $this->assertNull(collect($change->verifications()->sole()->results)->firstWhere('name', 'Sending a form twice'));
    }
}
