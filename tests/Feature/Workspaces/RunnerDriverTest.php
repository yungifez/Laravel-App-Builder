<?php

namespace Tests\Feature\Workspaces;

use App\Enums\BoxCommandStatus;
use App\Models\BoxCommand;
use App\Models\Runner;
use App\Workspaces\Boxes\BoxProviderManager;
use App\Workspaces\Drivers\RunnerDriver;
use App\Workspaces\WorkspaceManager;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fakes\FakeBoxRunner;
use Tests\TestCase;

class RunnerDriverTest extends TestCase
{
    use RefreshDatabase;

    protected FakeBoxRunner $runner;

    protected RunnerDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $runner = $this->runner = new FakeBoxRunner('local');
        Broadcast::extend('fake-runner', fn () => $runner);

        config([
            'broadcasting.default' => 'fake-runner',
            'broadcasting.connections.fake-runner' => ['driver' => 'fake-runner'],
            'workspaces.boxes.static' => ['runner' => 'local', 'token' => 'runner-token', 'service_host' => 'runner'],
            'workspaces.drivers.runner.answer_seconds' => 1,
            'workspaces.drivers.runner.grace_seconds' => 0,
            'workspaces.drivers.runner.poll_ms' => 10,
        ]);

        $this->driver = $this->app->make(WorkspaceManager::class)->driver('runner');
    }

    public function test_a_workspace_is_opened_by_the_runner_that_serves_it()
    {
        $box = $this->driver->create(new WorkspaceSpec('workspace-1', 'box', 1, 256, 64));

        $this->assertSame('workspace-1', $box);
        $this->assertSame([['type' => 'open', 'box' => 'workspace-1', 'payload' => []]], $this->runner->received);
        $this->assertSame('http://runner:8123', $this->driver->serviceUrl($box, 8123));
    }

    public function test_a_box_that_did_not_open_is_cleared_on_its_machine()
    {
        $this->runner->on('open', fn () => ['exit_code' => 1, 'output' => '', 'error_output' => 'No space left on device', 'timed_out' => false, 'duration_ms' => 3]);

        try {
            $this->driver->create(new WorkspaceSpec('workspace-1', 'box', 1, 256, 64));
            $this->fail('A box that did not open must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No space left on device', $exception->getMessage());
        }

        $this->assertSame(['open', 'close'], array_column($this->runner->received, 'type'));
    }

    public function test_a_command_runs_in_the_box_and_its_credentials_do_not_outlive_it()
    {
        $this->runner->on('exec', fn (array $payload) => [
            'exit_code' => 0,
            'output' => implode(' ', $payload['command']).' with '.$payload['env']['API_KEY'],
            'error_output' => '',
            'timed_out' => false,
            'duration_ms' => 12,
        ]);

        $result = $this->driver->exec('workspace-1', ['php', '-v'], 30, ['API_KEY' => 'secret']);

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('php -v with secret', $result->output);
        $this->assertSame(12, $result->durationMs);
        $this->assertNull(BoxCommand::sole()->payload);
    }

    public function test_a_command_nobody_takes_is_reported_as_timed_out()
    {
        config(['workspaces.boxes.static.runner' => 'elsewhere']);
        $this->app->forgetInstance(BoxProviderManager::class);
        $driver = $this->app->make(WorkspaceManager::class)->createRunnerDriver();

        $result = $driver->exec('workspace-1', ['php', '-v'], 1, ['API_KEY' => 'secret']);

        $this->assertTrue($result->timedOut);
        $this->assertSame('No runner took the command.', $result->errorOutput);
        $this->assertSame(BoxCommandStatus::Lost, BoxCommand::sole()->status);
        $this->assertNull(BoxCommand::sole()->payload);
    }

    public function test_a_caller_that_gives_up_stops_the_command_in_the_box()
    {
        $this->runner->on('exec', fn () => null);
        $ticks = 0;

        try {
            $this->driver->exec('workspace-1', ['sleep', '60'], 60, [], function () use (&$ticks) {
                if (++$ticks === 3) {
                    throw new RuntimeException('Lease lost.');
                }
            });
            $this->fail('The exception must reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Lease lost.', $exception->getMessage());
        }

        $command = BoxCommand::sole();
        $this->assertNotNull($command->cancel_requested_at);
        $this->assertSame([$command->id], $this->runner->cancelled);
    }

    public function test_a_command_is_lost_soon_when_its_pool_runner_stops_asking_for_work()
    {
        // The runner took the command, then its machine went away.
        $this->runner->on('exec', fn () => null);
        Runner::factory()->create(['name' => 'local', 'last_seen_at' => now()->subMinutes(10)]);
        $started = microtime(true);

        $result = $this->driver->exec('workspace-1', ['php', 'artisan', 'test'], 600);

        $this->assertLessThan(10, microtime(true) - $started);
        $this->assertTrue($result->timedOut);
        $this->assertSame('The runner stopped answering.', $result->errorOutput);
        $this->assertSame(BoxCommandStatus::Lost, BoxCommand::sole()->status);
    }

    public function test_files_travel_both_ways_and_paths_stay_inside_the_workspace()
    {
        $written = null;
        $this->runner
            ->on('write', function (array $payload) use (&$written) {
                $written = [$payload['path'], base64_decode($payload['contents'])];

                return ['exit_code' => 0, 'output' => '', 'error_output' => '', 'timed_out' => false, 'duration_ms' => 1];
            })
            ->on('read', fn (array $payload) => ['exit_code' => 0, 'output' => '', 'error_output' => '', 'timed_out' => false, 'duration_ms' => 1, 'contents' => base64_encode("read {$payload['path']}")]);

        $this->driver->writeFile('workspace-1', '.git/agent-task/task.json', '{"a":1}');

        $this->assertSame(['.git/agent-task/task.json', '{"a":1}'], $written);
        $this->assertSame('read notes.md', $this->driver->readFile('workspace-1', 'notes.md'));

        $this->expectException(\InvalidArgumentException::class);
        $this->driver->readFile('workspace-1', '../../.env');
    }

    public function test_a_failed_file_command_throws()
    {
        $this->runner->on('read', fn () => ['exit_code' => 1, 'output' => '', 'error_output' => 'No such file', 'timed_out' => false, 'duration_ms' => 1]);

        $this->expectExceptionMessage('The workspace could not read: No such file');

        $this->driver->readFile('workspace-1', 'missing.txt');
    }

    public function test_the_runner_receives_the_file_tail_limit()
    {
        $this->runner->on('read', fn (array $payload) => [
            'exit_code' => 0, 'output' => '', 'error_output' => '', 'timed_out' => false, 'duration_ms' => 1,
            'contents' => base64_encode('recent'),
        ]);

        $this->assertSame('recent', $this->driver->readFile('workspace-1', 'app.log', 6));
        $this->assertSame(['path' => 'app.log', 'tail_bytes' => 6], $this->runner->received[0]['payload']);
        $this->assertSame('recent', base64_decode(BoxCommand::sole()->result['contents']));
    }

    public function test_the_real_runner_returns_only_the_requested_file_tail()
    {
        $result = Process::timeout(20)->run(['node', base_path('tests/Fixtures/box-runner-files.mjs'), base_path('resources/box-runner/runner.mjs')]);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }

    public function test_the_real_runner_gives_each_workspace_a_user_of_its_own()
    {
        // Switching users needs root, which the runner has in its box and
        // the tests do not: run tests/Fixtures/box-runner-users.mjs there.
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Needs root, as the runner has in its box.');
        }

        $result = Process::timeout(60)->run(['node', base_path('tests/Fixtures/box-runner-users.mjs'), base_path('resources/box-runner/runner.mjs')]);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }

    public function test_the_real_runner_fences_each_workspace_off_from_the_others_previews_and_the_private_network()
    {
        // The firewall needs root and iptables, as the runner has on a runner
        // machine: run tests/Fixtures/box-runner-firewall.mjs there.
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Needs root and iptables, as the runner has on its machine.');
        }

        $result = Process::timeout(90)->run(['node', base_path('tests/Fixtures/box-runner-firewall.mjs'), base_path('resources/box-runner/runner.mjs')]);

        if ($result->exitCode() === 77) {
            $this->markTestSkipped('This machine does not let the runner use iptables.');
        }

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }

    public function test_the_project_is_packed_without_secrets_and_the_archive_is_removed_afterwards()
    {
        $source = storage_path('framework/testing/source-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists("{$source}/app");
        File::put("{$source}/app/Team.php", '<?php');
        File::put("{$source}/.env", 'APP_KEY=secret');

        $listed = null;
        $this->runner->on('unpack', function (array $payload) use (&$listed) {
            $listed = trim((string) shell_exec('tar -tzf '.escapeshellarg(RunnerDriver::archivePath($payload['archive']))));

            return ['exit_code' => 0, 'output' => '', 'error_output' => '', 'timed_out' => false, 'duration_ms' => 1];
        });

        try {
            $this->driver->copyDirectory('workspace-1', $source);
        } finally {
            File::deleteDirectory($source);
        }

        $this->assertStringContainsString('./app/Team.php', (string) $listed);
        $this->assertStringNotContainsString('.env', (string) $listed);
        $this->assertSame([], File::glob(storage_path('app/private/box-archives/*')));
    }

    public function test_a_doorbell_that_cannot_ring_does_not_fail_the_command()
    {
        Broadcast::extend('broken', fn () => new class extends FakeBoxRunner
        {
            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Could not resolve host: reverb');
            }
        });
        config(['broadcasting.default' => 'broken', 'broadcasting.connections.broken' => ['driver' => 'broken']]);

        $result = $this->driver->exec('workspace-1', ['php', '-v'], 1);

        $this->assertTrue($result->timedOut);
        $this->assertSame('No runner took the command.', $result->errorOutput);
    }

    public function test_destroying_a_workspace_closes_it_in_the_box()
    {
        $this->driver->destroy('workspace-1');

        $this->assertSame('close', $this->runner->received[0]['type']);
    }
}
