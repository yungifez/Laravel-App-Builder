<?php

namespace Tests\Feature\Runs;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Previews\GrantPreviewAccess;
use App\Actions\Previews\MakePreviewPerson;
use App\Actions\Previews\SignInToPreview;
use App\Actions\Projects\ConnectOwnTool;
use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\DescribeRunProgress;
use App\Actions\Runs\FailRun;
use App\Actions\Runs\GrantWorkerAccess;
use App\Actions\Runs\NarrateWork;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Context\ProjectNotes;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Jobs\ExecuteRun;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class WorkerDriverTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        Sleep::fake();
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'worker',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);

        FeaturePlanner::fake([[
            'summary' => 'Teams get an optional description.',
            'acceptance_criteria' => ['Teams have a nullable description.'],
            'assumptions' => [],
            'tasks' => ['Add a nullable description property.'],
            'capabilities' => [],
            'understood_as' => 'Data change',
            'current_behavior' => 'Teams have only a name.',
            'preserve' => [['area' => null, 'statement' => 'Team names stay required.']],
            'steps' => [[
                'key' => 'description-field',
                'kind' => 'data',
                'label' => 'Team description',
                'file' => 'app/Models/Team.php',
                'symbol' => 'Team::$description',
                'detail' => 'Holds an optional description.',
            ]],
        ]]);
        ChangeReviewer::fake([['approved' => true, 'summary' => 'Looks right.', 'findings' => [], 'changes' => [], 'verify' => []]]);
    }

    public function test_the_run_waits_for_the_worker_and_tells_it_how_to_hand_the_change_back()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->assertSame(RunStatus::Implementing, $run->status, json_encode($run->events()->pluck('data', 'type')));
        $this->assertNotNull($run->workspace_id);
        $this->assertSame(0, $run->events()->where('type', 'model_call')->where('data->role', 'coder')->count());

        $this->tool('get_task', $token)->assertSee('Teams get an optional description.')->assertSee('submit_change');
        $this->tool('check_status', $token)->assertSee('Waiting for your change.');
    }

    public function test_a_handed_back_change_is_applied_and_checked_and_a_repair_is_the_whole_change_again()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.'])
            ->assertSee('Received.');

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertStringContainsString('+    public ?string $description = null;', (string) $run->featureRequest->patch);
        $this->assertSame('Added a description.', $run->events()->where('type', 'build_finished')->sole()->data['account']);
        $this->tool('check_status', $token)->assertSee('being checked');

        // The checks send it back: the worker hears so, and hands back the whole change again.
        $this->verify($run, VerificationStatus::Failed);
        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $this->tool('check_status', $token)->assertSee('The checks found problems.');
        $this->tool('get_task', $token)->assertSee('Fix these problems');

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Fixed it.'])->assertSee('Received.');
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);

        // The review sends it back too, in words the worker can act on.
        $this->verify($run, VerificationStatus::Passed);
        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $this->tool('get_task', $token)->assertSee('No test in the change checks: Teams have a nullable description.');
    }

    public function test_check_status_waits_while_the_change_is_with_us_and_answers_when_it_moves()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);

        // The checks send the change back during the second wait.
        $waits = 0;
        Sleep::whenFakingSleep(function () use ($run, &$waits) {
            if (++$waits === 2) {
                $this->verify($run, VerificationStatus::Failed);
            }
        });

        $this->tool('check_status', $token)->assertSee('The checks found problems.');
        $this->assertSame(2, $waits);

        // Waiting for the worker, it answers at once.
        $this->tool('check_status', $token)->assertSee('The checks found problems.');
        $this->assertSame(2, $waits);
    }

    public function test_check_status_says_when_a_change_stopped_on_our_side()
    {
        $run = $this->startRun();
        $token = app(ConnectOwnTool::class)->handle($run->featureRequest->project);
        $this->tool('get_task', $token);

        app(FailRun::class)->handle($run, 'This is our fault: something on our side stopped.', cause: 'worker_stopped');

        $this->tool('check_status', $token)
            ->assertSee('No change waits for you now. The last one ended')
            ->assertSee('stopped on our side, not because of your work')
            ->assertSee('This is our fault: something on our side stopped.');
    }

    public function test_a_connected_tool_needs_no_offer_to_connect_and_the_thread_says_it_wrote_the_change()
    {
        $run = $this->startRun();
        $token = app(ConnectOwnTool::class)->handle($run->featureRequest->project);
        $describe = fn () => app(DescribeFeatureRequest::class)->handle($run->featureRequest->refresh());

        $this->assertFalse($describe()['featureRequest']['can_work_yourself']);
        $this->assertFalse($describe()['run']['yours']['wrote']);

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);

        $this->assertTrue($describe()['run']['yours']['wrote']);
    }

    public function test_the_worker_opens_the_app_with_its_change_in_a_browser_apart_from_the_owner()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('open_preview', $token)->assertSee('not running yet');

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        $preview = Preview::factory()->ready()->create(['project_id' => $run->featureRequest->project_id, 'feature_request_id' => $run->feature_request_id]);
        $this->tool('check_status', $token)->assertSee('call open_preview');

        $this->mock(SignInToPreview::class, fn (MockInterface $mock) => $mock->shouldReceive('cookie')
            ->once()->with(Mockery::on(fn (Preview $on) => $on->is($preview)), '7')
            ->andReturn(['name' => 'app_session', 'value' => 'sealed', 'minutes' => 120]));

        $text = (string) $this->tool('open_preview', $token, ['path' => '/classes', 'person' => '7'])->json('result.content.0.text');
        preg_match('/grant=([A-Za-z0-9]+)/', $text, $grant);

        $this->assertStringContainsString('to=%2Fclasses', $text);
        // A grant of its own: the owner's grant and sessions stay as they were.
        $this->assertTrue(Cache::has(GrantPreviewAccess::sharedKey($preview, $grant[1])));
        $this->assertSame('sealed', Cache::get(GrantPreviewAccess::cookieKey($preview, $grant[1]))['value']);
        $this->assertNull($preview->refresh()->grant_hash);

        $this->tool('open_preview', $token, ['path' => '//evil.example'])->assertSee('path');
    }

    public function test_the_worker_signs_in_as_a_test_person_it_asks_the_app_to_make()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        Preview::factory()->ready()->create(['project_id' => $run->featureRequest->project_id, 'feature_request_id' => $run->feature_request_id]);

        $this->mock(MakePreviewPerson::class, fn (MockInterface $mock) => $mock->shouldReceive('in')->once()
            ->andReturn(['id' => '12', 'name' => 'Ada', 'email' => 'ada@example.test']));
        $this->mock(SignInToPreview::class, fn (MockInterface $mock) => $mock->shouldReceive('cookie')
            ->once()->with(Mockery::any(), '12')->andReturn(['name' => 'app_session', 'value' => 'sealed', 'minutes' => 120]));

        $this->tool('open_preview', $token, ['person' => 'new'])
            ->assertSee('signed in as a test person the app made: ada@example.test');
    }

    public function test_a_person_the_app_cannot_sign_in_points_the_worker_to_a_test_person()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        Preview::factory()->ready()->create(['project_id' => $run->featureRequest->project_id, 'feature_request_id' => $run->feature_request_id]);

        $this->mock(SignInToPreview::class, fn (MockInterface $mock) => $mock->shouldReceive('cookie')
            ->andThrow(ValidationException::withMessages(['person' => 'Your app could not sign them in. This is our fault. Try again.'])));

        $this->tool('open_preview', $token, ['person' => 'nobody@example.test'])->assertSee('as person to make a test person');
    }

    public function test_the_worker_runs_commands_on_its_change_in_our_workspace_which_stays_as_it_was()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $config = ['builder.agents.workers.try_commands' => [['cat'], ['sh', '-c']]];

        $this->tool('try_change', $token, ['patch' => $this->workersChange(), 'command' => ['cat', 'app/Models/Team.php']], $config)
            ->assertSee('Exit code 0.')
            ->assertSee('public ?string $description = null;');

        $made = (string) $this->tool('try_change', $token, ['patch' => $this->workersChange(), 'command' => ['sh', '-c', 'echo made > app/Made.php']], $config)
            ->json('result.content.0.text');
        $this->assertStringContainsString('+++ b/app/Made.php', $made);
        $this->assertStringNotContainsString('Team.php', $made, 'Only what the command wrote comes back.');

        $this->tool('try_change', $token, ['patch' => '', 'command' => ['cat', 'app/Models/Team.php']], $config)
            ->assertDontSee('description')
            ->assertDontSee('Made.php');
        $this->assertSame(2 + 1, $run->events()->where('type', 'worker_tried')->count());
    }

    public function test_the_worker_hears_why_a_try_did_not_run()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $config = ['builder.agents.workers.try_commands' => [['php', 'artisan']]];

        $this->tool('try_change', $token, ['patch' => '', 'command' => ['rm', '-rf', 'app']], $config)
            ->assertSee('This command cannot run here. Allowed commands start with: php artisan.');
        $this->tool('try_change', $token, ['patch' => str_replace("'Team'", "'Crew'", $this->workersChange()), 'command' => ['php', 'artisan', 'about']], $config)
            ->assertSee('Your patch did not apply')
            ->assertDontSee('agent-task');

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        $this->tool('try_change', $token, ['patch' => '', 'command' => ['php', 'artisan', 'about']], $config)
            ->assertSee('Commands run only while the change waits for you.');
    }

    public function test_a_test_that_starts_node_itself_is_sent_back_with_the_laravel_way()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $test = <<<'PATCH'
            diff --git a/tests/Feature/TeamScreenTest.php b/tests/Feature/TeamScreenTest.php
            new file mode 100644
            --- /dev/null
            +++ b/tests/Feature/TeamScreenTest.php
            @@ -0,0 +1,3 @@
            +<?php
            +
            +(new Symfony\Component\Process\Process(['node', 'tests/render.mjs']))->mustRun();

            PATCH;

        $this->tool('submit_change', $token, ['patch' => $this->workersChange().$test, 'summary' => 'Added a description.']);
        $this->verify($run, VerificationStatus::Passed);

        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $fix = (string) $this->tool('get_task', $token)->json('result.content.0.text');
        $this->assertStringContainsString('Line 3 of tests/Feature/TeamScreenTest.php starts Node from a PHP test.', $fix);
        $this->assertStringContainsString('assertInertia', $fix);
    }

    public function test_the_owner_watches_their_own_tool_work_in_plain_words()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $progress = fn () => app(DescribeRunProgress::class)->handle($run->refresh())['text'];

        $this->assertSame('Waiting for your Claude Code or Codex to ask for it', $progress());

        $this->tool('get_task', $token)->assertSee('share_progress');
        $this->assertSame('Your Claude Code or Codex is reading how your app works', $progress());

        $this->tool('share_progress', $token, ['doing' => 'Adding a short description to each team.'])->assertSee('Shared.');
        $this->tool('try_change', $token, ['patch' => $this->workersChange(), 'command' => ['cat', 'app/Models/Team.php']], ['builder.agents.workers.try_commands' => [['cat']]]);
        $this->assertSame('Your Claude Code or Codex is trying it out', $progress());

        $story = array_column(app(NarrateWork::class)->handle($run->refresh()), 'text');
        $this->assertContains('Adding a short description to each team.', $story);
        $this->assertContains('Tried it out', $story);
        $this->assertSame(1, app(DescribeRunProgress::class)->handle($run)['changed']);

        // The sentence can ride along with a command, saving the tool a turn.
        $this->tool('try_change', $token, ['patch' => $this->workersChange(), 'command' => ['cat', 'app/Models/Team.php'], 'doing' => 'Checking each team shows its description.'], ['builder.agents.workers.try_commands' => [['cat']]]);
        $this->assertContains('Checking each team shows its description.', array_column(app(NarrateWork::class)->handle($run->refresh()), 'text'));

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        $this->assertContains('Adding a short description to each team.', array_column(app(NarrateWork::class)->handle($run->refresh()), 'text'), 'The story stays once it is handed back.');
    }

    public function test_a_change_waiting_for_an_owners_tool_is_not_ahead_in_our_line()
    {
        $this->startRun();
        $ours = Run::factory()->create(['status' => RunStatus::Queued]);

        $this->assertNull(app(DescribeRunProgress::class)->handle($ours));
    }

    public function test_a_patch_that_does_not_apply_is_refused_with_the_reason_and_can_be_handed_back_again()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['patch' => str_replace("'Team'", "'Crew'", $this->workersChange()), 'summary' => 'Wrong base.'])
            ->assertSee('Received.');

        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $this->tool('check_status', $token)
            ->assertSee('Your change did not apply')
            ->assertSee('Team.php: patch does not apply')
            ->assertDontSee('agent-task');

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Right base.'])->assertSee('Received.');
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);
        $this->assertStringNotContainsString('Crew', (string) $run->featureRequest->patch);
    }

    public function test_notes_in_a_handed_back_change_are_left_out_so_they_never_clash_with_ours()
    {
        $run = $this->startRun(notes: true);
        $token = app(GrantWorkerAccess::class)->handle($run);

        $task = $this->tool('get_task', $token)->json('result.content.0.text');
        $this->assertStringContainsString('Do not create a .product-notes/ folder', $task);
        $this->assertStringNotContainsString('Keep the notes in', $task);

        $notes = <<<'PATCH'
            diff --git a/.product-notes/project.md b/.product-notes/project.md
            new file mode 100644
            --- /dev/null
            +++ b/.product-notes/project.md
            @@ -0,0 +1 @@
            +# The worker's own notes

            PATCH;

        $this->tool('submit_change', $token, ['patch' => $this->workersChange().$notes, 'summary' => 'Added a description.']);

        $this->assertSame(RunStatus::Verifying, $run->refresh()->status, json_encode($run->events()->where('type', 'worker_patch_refused')->pluck('data')));
        $this->assertNull($run->featureRequest->note_changes);
    }

    public function test_a_new_try_hears_what_stopped_the_last_one()
    {
        $stopped = FeatureRequest::factory()->create(['prompt' => 'Give teams a description.']);
        Run::factory()->for($stopped)->create([
            'status' => RunStatus::Failed,
            'feedback' => ['reason' => 'verification_failed', 'details' => ['Tests failed: the team page shows no description.']],
            'review' => ['approved' => false, 'summary' => '', 'findings' => [
                ['severity' => 'blocking', 'summary' => 'The description is never saved.', 'file' => 'app/Models/Team.php'],
                ['severity' => 'minor', 'summary' => 'A comment is long.', 'file' => null],
            ], 'changes' => [], 'classification' => ['requested' => [], 'may_also_affect' => [], 'unexpected' => [], 'unclaimed' => [], 'context_updates' => [], 'targets' => []]],
        ]);

        $run = $this->startRun(retryOf: $stopped);

        $task = $this->tool('get_task', app(GrantWorkerAccess::class)->handle($run))->json('result.content.0.text');

        $this->assertStringContainsString("## An earlier try at this change stopped\n\nIts work is not in the files", $task);
        $this->assertStringContainsString('- Tests failed: the team page shows no description.', $task);
        $this->assertStringContainsString('- The description is never saved. (app/Models/Team.php)', $task);
        $this->assertStringNotContainsString('A comment is long.', $task);
    }

    public function test_a_change_is_taken_only_while_the_run_waits_for_one()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['patch' => str_repeat('x', 3 * 1024), 'summary' => 'Too big.'], ['builder.agents.workers.max_patch_kb' => 2])
            ->assertSee('too large');
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Done.']);

        // Once it is being checked there is nothing to hand back.
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Again.'])->assertSee('not waiting for a patch');
        $this->assertSame(1, $run->events()->where('type', 'worker_submitted')->count());

        // A change our own agent writes takes no patch from outside.
        $ours = Run::factory()->implementing()->create(['driver' => 'sdk', 'plan' => $run->plan]);
        $this->tool('submit_change', app(GrantWorkerAccess::class)->handle($ours), ['patch' => $this->workersChange(), 'summary' => 'Sneaky.'])
            ->assertSee('nothing to hand back');
        $this->assertSame(0, $ours->events()->where('type', 'worker_submitted')->count());
    }

    public function test_the_reconciler_leaves_a_run_that_waits_for_its_worker_alone()
    {
        $run = $this->startRun();
        Queue::fake([ExecuteRun::class]);
        $this->travel((int) config('builder.construction.lease_seconds') + 60)->seconds();

        $this->artisan('runs:reconcile')->assertSuccessful();
        Queue::assertNothingPushed();

        // A change handed back while no one picked it up is resumed.
        $run->recordEvent('worker_submitted', ['patch' => $this->workersChange(), 'summary' => 'Done.']);
        $run->touch();
        $this->travel((int) config('builder.construction.lease_seconds') + 60)->seconds();

        $this->artisan('runs:reconcile')->assertSuccessful();
        Queue::assertPushed(ExecuteRun::class, 1);
    }

    protected function startRun(bool $notes = false, ?FeatureRequest $retryOf = null): Run
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);

        if ($notes) {
            app(ProjectNotes::class)->put($project, 'main', ['project.md' => "# Teams\n\nPeople work in teams.\n"]);
        }

        $featureRequest = FeatureRequest::factory()->for($project)->create(['prompt' => 'Give teams a description.', 'retry_of_id' => $retryOf?->id]);

        return app(StartRun::class)->handle($featureRequest)->refresh();
    }

    /**
     * The worker's change: a description on the team, against the starting point.
     */
    protected function workersChange(): string
    {
        return <<<'PATCH'
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
    }

    /**
     * Call one of the change's tools as a worker's MCP client would.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $config
     */
    protected function tool(string $tool, string $token, array $arguments = [], array $config = []): TestResponse
    {
        config($config);

        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(route('mcp.task'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])
            ->assertOk();
    }

    protected function verify(Run $run, VerificationStatus $status): void
    {
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => $status, 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => $status === VerificationStatus::Passed ? 'passed' : 'failed', 'exit_code' => $status === VerificationStatus::Passed ? 0 : 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'Team has no description.'],
        ], 'finished_at' => now()]);

        app(CompleteRunVerification::class)->handle($verification);
    }
}
