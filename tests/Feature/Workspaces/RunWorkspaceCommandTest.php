<?php

namespace Tests\Feature\Workspaces;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\WorkspaceStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\CommandResult;
use App\Workspaces\Exceptions\WorkspaceBusyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\FakesWorkspaces;
use Tests\TestCase;

class RunWorkspaceCommandTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    public function test_it_runs_the_command_and_records_the_result()
    {
        $driver = $this->fakeWorkspaces();
        $driver->onExec = fn () => new CommandResult(exitCode: 1, output: 'FAIL', errorOutput: 'boom', durationMs: 1234);
        config(['workspaces.commands.timeout' => 42]);
        $workspace = Workspace::factory()->create(['last_activity_at' => now()->subHour()]);

        $command = app(RunWorkspaceCommand::class)->handle($workspace, ['php', 'artisan', 'test']);

        $this->assertSame([['workspace' => $workspace->driver_id, 'command' => ['php', 'artisan', 'test'], 'timeout' => 42]], $driver->executed);
        $this->assertSame(['php', 'artisan', 'test'], $command->command);
        $this->assertSame(1, $command->exit_code);
        $this->assertFalse($command->timed_out);
        $this->assertSame(1234, $command->duration_ms);
        $this->assertSame('FAIL', $command->output);
        $this->assertSame('boom', $command->error_output);
        $this->assertTrue($workspace->fresh()->last_activity_at->greaterThan(now()->subMinute()));
    }

    public function test_an_explicit_timeout_is_passed_to_the_driver_and_timeouts_are_recorded()
    {
        $driver = $this->fakeWorkspaces();
        $driver->onExec = fn () => new CommandResult(exitCode: 124, output: '', errorOutput: '', durationMs: 1000, timedOut: true);
        $workspace = Workspace::factory()->create();

        $command = app(RunWorkspaceCommand::class)->handle($workspace, ['sleep', '10'], timeoutSeconds: 1);

        $this->assertSame(1, $driver->executed[0]['timeout']);
        $this->assertTrue($command->timed_out);
    }

    public function test_long_output_is_truncated_before_it_is_stored()
    {
        $driver = $this->fakeWorkspaces();
        $driver->onExec = fn () => new CommandResult(exitCode: 0, output: str_repeat('a', 500), errorOutput: '', durationMs: 1);
        config(['workspaces.commands.output_limit' => 100]);

        $command = app(RunWorkspaceCommand::class)->handle(Workspace::factory()->create(), ['yes']);

        $this->assertLessThanOrEqual(103, strlen($command->output));
    }

    public function test_commands_are_rejected_for_workspaces_that_are_not_ready()
    {
        $this->fakeWorkspaces();
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Destroyed]);

        $this->expectException(InvalidArgumentException::class);

        app(RunWorkspaceCommand::class)->handle($workspace, ['ls']);
    }

    public function test_an_owner_cannot_exceed_their_concurrent_command_limit()
    {
        $driver = $this->fakeWorkspaces();
        config(['workspaces.commands.per_owner' => 1, 'workspaces.commands.wait_seconds' => 0]);
        $owner = User::factory()->create();
        $first = Workspace::factory()->for($owner, 'owner')->create();
        $second = Workspace::factory()->for($owner, 'owner')->create();
        $otherOwnersWorkspace = Workspace::factory()->create();
        $nested = [];

        $driver->onExec = function (string $workspaceId) use ($first, $second, $otherOwnersWorkspace, &$nested) {
            if ($workspaceId === $first->driver_id) {
                try {
                    app(RunWorkspaceCommand::class)->handle($second, ['ls']);
                    $nested['same owner'] = 'ran';
                } catch (WorkspaceBusyException) {
                    $nested['same owner'] = 'busy';
                }

                app(RunWorkspaceCommand::class)->handle($otherOwnersWorkspace, ['ls']);
                $nested['other owner'] = 'ran';
            }

            return new CommandResult(exitCode: 0, output: '', errorOutput: '', durationMs: 1);
        };

        app(RunWorkspaceCommand::class)->handle($first, ['composer', 'install']);

        $this->assertSame(['same owner' => 'busy', 'other owner' => 'ran'], $nested);

        app(RunWorkspaceCommand::class)->handle($second, ['ls']);
        $this->assertSame(1, $second->commands()->count(), 'The slot is released after the first command finishes.');
    }
}
