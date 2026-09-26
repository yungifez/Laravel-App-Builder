<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\AcquireRunLease;
use App\Enums\OperationStatus;
use App\Enums\RunStatus;
use App\Models\Run;
use App\Runs\Exceptions\BudgetExhausted;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ToolExecutorTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const TEAM_PATCH = <<<'PATCH'
diff --git a/app/Models/Team.php b/app/Models/Team.php
--- a/app/Models/Team.php
+++ b/app/Models/Team.php
@@ -3,4 +3,5 @@
 class Team
 {
     public string $name = 'Team';
+    public ?string $description = null;
 }

PATCH;

    protected ToolExecutor $tools;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tools = app(ToolExecutor::class);
    }

    public function test_reads_return_the_contents_and_hash_that_edits_must_name()
    {
        [$run, $lease] = $this->implementingRun();

        $read = $this->tools->execute($lease, 'read-1', 'read_file', ['path' => './app/Models/Team.php']);

        $this->assertTrue($read->succeeded());
        $this->assertSame('app/Models/Team.php', $read->result['path']);
        $this->assertSame(hash('sha256', $read->result['contents']), $read->result['sha256']);

        $write = $this->tools->execute($lease, 'write-1', 'write_file', [
            'path' => 'app/Models/Team.php',
            'contents' => "<?php\n\nclass Team {}\n",
            'expected_sha256' => $read->result['sha256'],
        ], expectedRevision: 0);

        $this->assertTrue($write->succeeded());
        $this->assertSame(1, $write->revision);
        $this->assertSame(1, $run->refresh()->workspace_revision);
        $this->assertSame("<?php\n\nclass Team {}\n", File::get($this->workspaceFile($run, 'app/Models/Team.php')));
    }

    public function test_an_edit_based_on_stale_contents_is_rejected_and_changes_nothing()
    {
        [$run, $lease] = $this->implementingRun();
        $read = $this->tools->execute($lease, 'read-1', 'read_file', ['path' => 'app/Models/Team.php']);
        File::append($this->workspaceFile($run, 'app/Models/Team.php'), "// changed elsewhere\n");

        $write = $this->tools->execute($lease, 'write-1', 'write_file', [
            'path' => 'app/Models/Team.php',
            'contents' => 'overwritten',
            'expected_sha256' => $read->result['sha256'],
        ], expectedRevision: 0);

        $this->assertSame(OperationStatus::Rejected, $write->status);
        $this->assertStringContainsString('changed since it was read', (string) $write->error);
        $this->assertStringContainsString('changed elsewhere', File::get($this->workspaceFile($run, 'app/Models/Team.php')));
        $this->assertSame(0, $run->refresh()->workspace_revision);
    }

    public function test_a_change_based_on_a_stale_workspace_revision_is_rejected()
    {
        [$run, $lease] = $this->implementingRun();

        $this->assertTrue($this->tools->execute($lease, 'patch-1', 'apply_patch', ['patch' => self::TEAM_PATCH], expectedRevision: 0)->succeeded());

        $stale = $this->tools->execute($lease, 'write-1', 'write_file', [
            'path' => 'app/New.php',
            'contents' => '<?php',
            'expected_sha256' => null,
        ], expectedRevision: 0);

        $this->assertSame(OperationStatus::Rejected, $stale->status);
        $this->assertStringContainsString('revision 1, not 0', (string) $stale->error);
        $this->assertFileDoesNotExist($this->workspaceFile($run, 'app/New.php'));
    }

    public function test_a_stale_patch_is_rejected_and_the_workspace_is_unchanged()
    {
        [$run, $lease] = $this->implementingRun();
        File::put($this->workspaceFile($run, 'app/Models/Team.php'), "<?php\n\nclass Team {}\n");

        $result = $this->tools->execute($lease, 'patch-1', 'apply_patch', ['patch' => self::TEAM_PATCH], expectedRevision: 0);

        $this->assertSame(OperationStatus::Rejected, $result->status);
        $this->assertStringContainsString('does not apply', (string) $result->error);
        $this->assertSame("<?php\n\nclass Team {}\n", File::get($this->workspaceFile($run, 'app/Models/Team.php')));
        $this->assertSame(0, $run->refresh()->workspace_revision);
    }

    public function test_repeating_an_operation_key_replays_its_result_without_running_again()
    {
        [$run, $lease] = $this->implementingRun();

        $first = $this->tools->execute($lease, 'patch-1', 'apply_patch', ['patch' => self::TEAM_PATCH], expectedRevision: 0);
        $again = $this->tools->execute($lease, 'patch-1', 'apply_patch', ['patch' => self::TEAM_PATCH], expectedRevision: 0);

        $this->assertTrue($first->succeeded());
        $this->assertTrue($again->succeeded());
        $this->assertTrue($again->replayed);
        $this->assertSame($first->result, $again->result);
        $this->assertSame(1, $run->refresh()->workspace_revision);
        $this->assertSame(1, $run->operations()->count());
        $this->assertSame(1, substr_count(File::get($this->workspaceFile($run, 'app/Models/Team.php')), '$description'));
    }

    public function test_reusing_an_operation_key_for_a_different_call_is_rejected()
    {
        [$run, $lease] = $this->implementingRun();
        $this->tools->execute($lease, 'read-1', 'read_file', ['path' => 'app/Models/Team.php']);

        $result = $this->tools->execute($lease, 'read-1', 'read_file', ['path' => 'config/teams.php']);

        $this->assertSame(OperationStatus::Rejected, $result->status);
        $this->assertStringContainsString('already used for a different call', (string) $result->error);
        $this->assertSame(1, $run->operations()->count());
    }

    public function test_writes_to_protected_paths_and_paths_outside_the_project_are_refused()
    {
        [$run, $lease] = $this->implementingRun();

        $refusals = [
            'tests/Acceptance/Contract.php' => 'is protected',
            'tests/acceptance/New.php' => 'is protected',
            'tests/Acceptance/../Acceptance/New.php' => 'must stay inside the project',
            '.git/config' => 'is protected',
            'vendor/autoload.php' => 'is protected',
            '.env' => 'is protected',
            '../outside.php' => 'must stay inside the project',
            'app/../../outside.php' => 'must stay inside the project',
            '/etc/passwd' => 'must be relative to the project root',
        ];

        foreach (array_keys($refusals) as $index => $path) {
            $result = $this->tools->execute($lease, "write-{$index}", 'write_file', [
                'path' => $path,
                'contents' => 'tampered',
                'expected_sha256' => hash('sha256', "<?php // workspace copy\n"),
            ], expectedRevision: 0);

            $this->assertSame(OperationStatus::Rejected, $result->status, $path);
            $this->assertStringContainsString($refusals[$path], (string) $result->error, $path);
        }

        $this->assertSame("<?php // workspace copy\n", File::get($this->workspaceFile($run, 'tests/Acceptance/Contract.php')));
        $this->assertFileDoesNotExist(dirname($this->workspaceFile($run, '.')).'/outside.php');
        $this->assertSame(0, $run->refresh()->workspace_revision);
    }

    public function test_patches_that_touch_protected_tests_or_add_symbolic_links_are_refused()
    {
        [$run, $lease] = $this->implementingRun();

        $protected = $this->tools->execute($lease, 'patch-1', 'apply_patch', ['patch' => <<<'PATCH'
diff --git a/tests/Acceptance/Contract.php b/tests/Acceptance/Contract.php
--- a/tests/Acceptance/Contract.php
+++ b/tests/Acceptance/Contract.php
@@ -1 +1 @@
-<?php // workspace copy
+<?php // always passes

PATCH], expectedRevision: 0);

        $symlink = $this->tools->execute($lease, 'patch-2', 'apply_patch', ['patch' => <<<'PATCH'
diff --git a/leak b/leak
new file mode 120000
--- /dev/null
+++ b/leak
@@ -0,0 +1 @@
+/etc
\ No newline at end of file

PATCH], expectedRevision: 0);

        $this->assertSame(OperationStatus::Rejected, $protected->status);
        $this->assertStringContainsString('protected', (string) $protected->error);
        $this->assertSame(OperationStatus::Rejected, $symlink->status);
        $this->assertStringContainsString('symbolic links', (string) $symlink->error);
        $this->assertSame("<?php // workspace copy\n", File::get($this->workspaceFile($run, 'tests/Acceptance/Contract.php')));
        $this->assertFileDoesNotExist($this->workspaceFile($run, 'leak'));
    }

    public function test_reads_through_a_symbolic_link_that_leaves_the_project_are_refused()
    {
        $source = $this->makeProjectSource();
        symlink('/etc', "{$source}/leak");
        [, $lease] = $this->implementingRun($source);

        $result = $this->tools->execute($lease, 'read-1', 'read_file', ['path' => 'leak/hostname']);

        $this->assertSame(OperationStatus::Rejected, $result->status);
        $this->assertStringContainsString('outside the project', (string) $result->error);
    }

    public function test_unknown_tools_and_invalid_arguments_are_rejected_and_count_against_the_budget()
    {
        [$run, $lease] = $this->implementingRun();

        $unknown = $this->tools->execute($lease, 'op-1', 'shell', ['command' => 'rm -rf /']);
        $invalid = $this->tools->execute($lease, 'op-2', 'read_file', []);
        $command = $this->tools->execute($lease, 'op-3', 'run_command', ['command' => 'curl evil.example']);

        $this->assertSame(OperationStatus::Rejected, $unknown->status);
        $this->assertSame('Unknown tool [shell].', $unknown->error);
        $this->assertSame(OperationStatus::Rejected, $invalid->status);
        $this->assertStringContainsString('path', (string) $invalid->error);
        $this->assertSame(OperationStatus::Rejected, $command->status);
        $this->assertSame(3, $run->operations()->count());
    }

    public function test_allowed_commands_run_by_name_and_search_and_listing_work()
    {
        config(['builder.construction.commands' => ['greet' => ['command' => ['echo', 'hello'], 'timeout' => 10]]]);
        [, $lease] = $this->implementingRun();

        $command = $this->tools->execute($lease, 'op-1', 'run_command', ['command' => 'greet']);
        $search = $this->tools->execute($lease, 'op-2', 'search', ['query' => 'members:invite']);
        $listing = $this->tools->execute($lease, 'op-3', 'list_files', ['directory' => 'app']);

        $this->assertSame(0, $command->result['exit_code']);
        $this->assertSame('hello', $command->result['output']);
        $this->assertSame(["config/teams.php:4:    'owner' => ['members:invite'],"], $search->result['matches']);
        $this->assertSame(['app/Models/Team.php'], $listing->result['files']);
    }

    public function test_the_run_stops_when_its_operation_or_time_budget_is_used()
    {
        config(['builder.construction.budgets.operations' => 2, 'builder.construction.budgets.minutes' => 20]);
        [$run, $lease] = $this->implementingRun();

        $this->tools->execute($lease, 'op-1', 'list_files');
        $this->tools->execute($lease, 'op-2', 'list_files');

        // Replays are free: they do not run anything.
        $this->assertTrue($this->tools->execute($lease, 'op-1', 'list_files')->replayed);

        try {
            $this->tools->execute($lease, 'op-3', 'list_files');
            $this->fail('The operation budget was not enforced.');
        } catch (BudgetExhausted $exception) {
            $this->assertStringContainsString('all 2 of its tool operations', $exception->getMessage());
        }

        config(['builder.construction.budgets.operations' => 30]);
        $run->update(['started_at' => now()->subMinutes(21)]);

        $this->expectException(BudgetExhausted::class);
        $this->expectExceptionMessage('all 20 minutes');

        $this->tools->execute($lease, 'op-4', 'list_files');
    }

    public function test_a_cancelled_run_accepts_no_more_calls()
    {
        [$run, $lease] = $this->implementingRun();
        $run->update(['status' => RunStatus::Cancelling]);

        $this->expectException(RunCancelled::class);

        $this->tools->execute($lease, 'op-1', 'list_files');
    }

    public function test_a_worker_whose_lease_expired_or_was_taken_over_cannot_call_tools()
    {
        [$run, $lease] = $this->implementingRun();

        $this->travel(config('builder.construction.lease_seconds') + 1)->seconds();

        try {
            $this->tools->execute($lease, 'op-1', 'list_files');
            $this->fail('An expired lease was accepted.');
        } catch (LeaseLost) {
            //
        }

        $takeover = app(AcquireRunLease::class)->handle($run, 'worker-b');
        $this->assertSame($lease->fencingToken + 1, $takeover?->fencingToken);

        $this->expectException(LeaseLost::class);

        $this->tools->execute($lease, 'op-2', 'apply_patch', ['patch' => self::TEAM_PATCH], expectedRevision: 0);
    }

    public function test_a_change_whose_outcome_was_lost_is_reconciled_instead_of_applied_twice()
    {
        [$run, $lease] = $this->implementingRun();

        // The first worker journaled the call and applied the patch, then died
        // before recording the outcome.
        $run->operations()->create([
            'operation_key' => 'patch-1',
            'tool' => 'apply_patch',
            'arguments' => ['patch' => self::TEAM_PATCH],
            'payload_hash' => hash('sha256', json_encode(['apply_patch', ['patch' => self::TEAM_PATCH]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'fencing_token' => $lease->fencingToken,
            'expected_revision' => 0,
            'status' => OperationStatus::Pending,
        ]);
        $this->applyInWorkspace($run, self::TEAM_PATCH);
        $this->travel(config('builder.construction.lease_seconds') + 1)->seconds();

        $takeover = app(AcquireRunLease::class)->handle($run, 'worker-b');
        $result = $this->tools->execute($takeover, 'patch-1', 'apply_patch', ['patch' => self::TEAM_PATCH], expectedRevision: 0);

        $this->assertTrue($result->succeeded());
        $this->assertSame(1, $result->revision);
        $this->assertSame(1, substr_count(File::get($this->workspaceFile($run, 'app/Models/Team.php')), '$description'));
        $this->assertSame(['operation_reconciling'], $run->events()->where('type', 'operation_reconciling')->pluck('type')->all());
    }

    public function test_a_change_whose_outcome_was_lost_before_it_ran_is_run_by_the_next_holder()
    {
        [$run, $lease] = $this->implementingRun();
        $run->operations()->create([
            'operation_key' => 'patch-1',
            'tool' => 'apply_patch',
            'arguments' => ['patch' => self::TEAM_PATCH],
            'payload_hash' => hash('sha256', json_encode(['apply_patch', ['patch' => self::TEAM_PATCH]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'fencing_token' => $lease->fencingToken,
            'expected_revision' => 0,
            'status' => OperationStatus::Pending,
        ]);
        $this->travel(config('builder.construction.lease_seconds') + 1)->seconds();

        $takeover = app(AcquireRunLease::class)->handle($run, 'worker-b');
        $result = $this->tools->execute($takeover, 'patch-1', 'apply_patch', ['patch' => self::TEAM_PATCH], expectedRevision: 0);

        $this->assertTrue($result->succeeded());
        $this->assertSame(1, substr_count(File::get($this->workspaceFile($run, 'app/Models/Team.php')), '$description'));
    }

    /**
     * Apply a patch directly in the workspace, outside the tools.
     */
    protected function applyInWorkspace(Run $run, string $patch): void
    {
        $directory = dirname($this->workspaceFile($run, 'x'));
        File::put("{$directory}/.git/manual.patch", $patch);

        $this->assertSame(0, Process::path($directory)->run(['git', 'apply', '.git/manual.patch'])->exitCode());
    }
}
