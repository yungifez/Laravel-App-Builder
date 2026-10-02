<?php

namespace Tests\Feature\Workspaces;

use App\Enums\BoxCommandStatus;
use App\Models\BoxCommand;
use App\Workspaces\Drivers\RunnerDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RunnerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workspaces.boxes.static' => ['runner' => 'local', 'token' => 'runner-token', 'service_host' => 'runner'],
            'workspaces.drivers.runner.socket_url' => 'ws://reverb:8080',
            'broadcasting.connections.reverb.key' => 'app-key',
            'broadcasting.connections.reverb.secret' => 'app-secret',
        ]);
    }

    public function test_only_a_runner_with_a_valid_token_gets_in()
    {
        $this->postJson('/api/runner/hello')->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/runner/hello')->assertUnauthorized();

        config(['workspaces.boxes.static.token' => '']);
        $this->withToken('')->postJson('/api/runner/hello')->assertUnauthorized();
    }

    public function test_hello_tells_the_runner_where_its_doorbell_is()
    {
        $this->withToken('runner-token')->postJson('/api/runner/hello')
            ->assertOk()
            ->assertJson([
                'runner' => 'local',
                'socket' => ['url' => 'ws://reverb:8080', 'key' => 'app-key', 'channel' => 'private-runner.local'],
            ]);
    }

    public function test_a_runner_may_listen_only_on_its_own_channel()
    {
        $this->withToken('runner-token')
            ->postJson('/api/runner/socket-auth', ['socket_id' => '123.456', 'channel_name' => 'private-runner.local'])
            ->assertOk()
            ->assertExactJson(['auth' => 'app-key:'.hash_hmac('sha256', '123.456:private-runner.local', 'app-secret')]);

        $this->withToken('runner-token')
            ->postJson('/api/runner/socket-auth', ['socket_id' => '123.456', 'channel_name' => 'private-runner.other'])
            ->assertForbidden();
    }

    public function test_each_command_is_handed_out_once_and_only_to_its_runner()
    {
        $mine = $this->command(['payload' => ['command' => ['php', '-v'], 'env' => ['API_KEY' => 'secret']]]);
        $this->command(['runner' => 'other']);

        $this->withToken('runner-token')->postJson('/api/runner/commands/claim')
            ->assertOk()
            ->assertExactJson(['commands' => [[
                'id' => $mine->id,
                'box' => 'workspace-1',
                'type' => 'exec',
                'payload' => ['command' => ['php', '-v'], 'env' => ['API_KEY' => 'secret']],
                'timeout_seconds' => 30,
            ]], 'cancel' => []]);

        $this->withToken('runner-token')->postJson('/api/runner/commands/claim')->assertExactJson(['commands' => [], 'cancel' => []]);
        $this->assertSame(BoxCommandStatus::Claimed, $mine->refresh()->status);
    }

    public function test_a_runner_that_starts_again_has_lost_the_commands_it_took_before()
    {
        $running = $this->command(['status' => BoxCommandStatus::Claimed]);
        $waiting = $this->command();
        $elsewhere = $this->command(['runner' => 'other', 'status' => BoxCommandStatus::Claimed]);

        $this->withToken('runner-token')->postJson('/api/runner/hello')->assertOk();

        $this->assertSame(BoxCommandStatus::Lost, $running->refresh()->status);
        $this->assertSame('The runner restarted while the command ran.', $running->result['error_output']);
        $this->assertNull($running->payload);
        $this->assertSame(BoxCommandStatus::Queued, $waiting->refresh()->status, 'The runner takes it on its next poll.');
        $this->assertSame(BoxCommandStatus::Claimed, $elsewhere->refresh()->status);
    }

    public function test_the_runner_hears_which_running_commands_to_stop()
    {
        $command = $this->command(['status' => BoxCommandStatus::Claimed, 'cancel_requested_at' => now()]);

        $this->withToken('runner-token')->postJson('/api/runner/commands/claim')
            ->assertJson(['cancel' => [$command->id]]);
    }

    public function test_a_result_ends_the_command_once_and_removes_its_payload()
    {
        $command = $this->command(['status' => BoxCommandStatus::Claimed]);
        $result = ['exit_code' => 0, 'output' => 'PHP 8.4', 'error_output' => '', 'timed_out' => false, 'duration_ms' => 40];

        $this->withToken('runner-token')->postJson("/api/runner/commands/{$command->id}/result", $result)->assertNoContent();
        $this->withToken('runner-token')->postJson("/api/runner/commands/{$command->id}/result", [...$result, 'output' => 'late'])->assertNoContent();

        $command->refresh();
        $this->assertSame(BoxCommandStatus::Finished, $command->status);
        $this->assertSame('PHP 8.4', $command->result['output'] ?? null);
        $this->assertNull($command->payload);
    }

    public function test_a_runner_cannot_report_on_another_runners_command()
    {
        $command = $this->command(['runner' => 'other', 'status' => BoxCommandStatus::Claimed]);

        $this->withToken('runner-token')
            ->postJson("/api/runner/commands/{$command->id}/result", ['exit_code' => 0, 'output' => '', 'error_output' => '', 'timed_out' => false, 'duration_ms' => 1])
            ->assertForbidden();

        $this->assertSame(BoxCommandStatus::Claimed, $command->refresh()->status);
    }

    public function test_the_archive_goes_only_to_the_runner_unpacking_it()
    {
        File::ensureDirectoryExists(dirname($path = RunnerDriver::archivePath('abc123.tar.gz')));
        File::put($path, 'archive');

        try {
            $unpack = $this->command(['type' => 'unpack', 'payload' => ['archive' => 'abc123.tar.gz'], 'status' => BoxCommandStatus::Claimed]);
            $other = $this->command(['runner' => 'other', 'type' => 'unpack', 'payload' => ['archive' => 'abc123.tar.gz']]);

            $this->withToken('runner-token')->get("/api/runner/commands/{$unpack->id}/archive")->assertOk();
            $this->withToken('runner-token')->get("/api/runner/commands/{$other->id}/archive")->assertNotFound();
        } finally {
            File::delete($path);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function command(array $attributes = []): BoxCommand
    {
        return BoxCommand::create([
            'runner' => 'local',
            'box' => 'workspace-1',
            'type' => 'exec',
            'payload' => [],
            'timeout_seconds' => 30,
            'status' => BoxCommandStatus::Queued,
            ...$attributes,
        ]);
    }
}
