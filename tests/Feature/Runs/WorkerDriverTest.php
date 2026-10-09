<?php

namespace Tests\Feature\Runs;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Features\HandChangeToOwner;
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
use App\Ai\Agents\NotesKeeper;
use App\Ai\Agents\TestWriter;
use App\Context\ProjectNotes;
use App\Enums\PreviewStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\VerificationStatus;
use App\Jobs\ExecuteRun;
use App\Jobs\StartPreview;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Events\PromptingAgent;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class WorkerDriverTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    /**
     * A test written from the plan, and the same test bent to pass.
     */
    protected const WRITTEN = "<?php\n\ntest('a team keeps its description', fn () => expect(true)->toBeTrue());\n";

    protected const BENT = "<?php\n\ntest('a team keeps its description', fn () => expect(true)->toBeTrue())->skip();\n";

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
            'cases' => [['base' => 'A team saved with a description keeps it.', 'alternate' => null, 'no_alternate' => 'A description is only set one way.', 'exception' => null, 'no_exception' => 'Nothing about a description is refused.']],
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
        NotesKeeper::fake([['files' => []]]);
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

    public function test_a_tool_started_in_an_empty_folder_gets_the_code_and_one_in_a_copy_starts_from_its_commit()
    {
        $run = $this->startRun();
        $run->featureRequest->update(['base_revision' => 'a1b2c3d']);
        $token = app(GrantWorkerAccess::class)->handle($run);
        $text = (string) $this->tool('get_task', $token)->json('result.content.0.text');

        // Run headless in a new temporary folder, it has no copy of the app.
        $this->assertStringContainsString('If the folder you were started in is empty, get the code at', $text);
        $this->assertStringContainsString('/worker-code/'.$run->uuid.'?', $text);
        $this->assertStringContainsString('diff against HEAD', $text);
        $this->assertStringContainsString('git -c user.name=start -c user.email=start@localhost -c commit.gpgsign=false commit -qm start', $text);
        // In the owner's copy it starts from the change's commit, as before.
        $this->assertStringContainsString('Otherwise you are in a copy of the app', $text);
        $this->assertStringContainsString('Start from commit a1b2c3d', $text);
    }

    public function test_a_change_handed_in_points_the_tool_to_its_result_not_a_new_brief()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $run->update(['status' => RunStatus::Verifying]);

        $this->tool('get_task', $token)
            ->assertSee('Your change was handed in and is being checked. Call check_status for the result.')
            ->assertDontSee('Teams get an optional description.');
    }

    public function test_a_fix_started_in_an_empty_folder_makes_the_whole_change_again()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.'])->assertSee('Received.');
        $this->verify($run, VerificationStatus::Failed);

        $this->tool('get_task', $token)
            ->assertSee('make the whole change again with the fixes below')
            ->assertSee('Fix these problems');
    }

    public function test_a_tool_writing_every_change_is_never_told_to_work_in_the_folder_it_started_in()
    {
        $run = $this->startRun();
        $token = app(ConnectOwnTool::class)->handle($run->featureRequest->project);
        $text = (string) $this->tool('get_task', $token)->json('result.content.0.text');

        $this->assertStringNotContainsString('copy of the app: do as follows', $text);
        $this->assertStringContainsString('never into the folder you were started in', $text);
    }

    public function test_a_change_handed_over_while_we_plan_it_is_written_by_the_owners_tool()
    {
        config(['builder.construction.driver' => 'sdk']);

        // The owner takes the change over while our planner is working on it.
        Event::listen(PromptingAgent::class, function (PromptingAgent $event) {
            $featureRequest = FeatureRequest::query()->latest('id')->firstOrFail();

            if ($event->prompt->agent instanceof FeaturePlanner && $featureRequest->latestRun?->driver === 'sdk') {
                app(HandChangeToOwner::class)->handle($featureRequest, $featureRequest->project->owner);
            }
        });

        $run = $this->startRun();

        $this->assertSame('worker', $run->driver);
        $this->assertSame(RunStatus::Implementing, $run->status, json_encode($run->events()->pluck('data', 'type')));
        $this->assertSame(1, FeatureRequest::query()->count());
        $this->assertTrue($run->events()->where('type', 'handed_to_owner')->exists());
        $this->assertSame(0, $run->events()->where('type', 'model_call')->where('data->role', 'coder')->count());
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

        app(FailRun::class)->handle($run, 'This is our fault: something on our side stopped.', cause: StopReason::WorkerStopped);

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

        $this->tool('submit_change', $token, ['task' => $this->taskCode($token), 'patch' => $this->workersChange(), 'summary' => 'Added a description.']);

        $this->assertTrue($describe()['run']['yours']['wrote']);
    }

    public function test_a_tool_writing_every_change_names_its_change_with_the_code_it_was_given()
    {
        $run = $this->startRun();
        $token = app(ConnectOwnTool::class)->handle($run->featureRequest->project);
        $code = $this->taskCode($token);

        $this->tool('share_progress', $token, ['task' => $code, 'doing' => 'Adding a description.'])->assertSee('Shared.');
        $this->tool('submit_change', $token, ['task' => $code, 'patch' => $this->workersChange(), 'summary' => 'Added a description.'])->assertSee('Received.');
        $this->tool('check_status', $token, ['task' => $code])->assertSee('being checked');
    }

    public function test_a_second_session_takes_the_change_over_and_the_first_is_told_to_stop()
    {
        $run = $this->startRun();
        $token = app(ConnectOwnTool::class)->handle($run->featureRequest->project);
        $first = $this->taskCode($token);
        $second = $this->taskCode($token);

        $this->tool('submit_change', $token, ['task' => $first, 'patch' => $this->workersChange(), 'summary' => 'Old work.'])
            ->assertSee('took this change over')
            ->assertSee('hand nothing back');
        $this->tool('check_status', $token, ['task' => $first])->assertSee('Stop working on it now');
        $this->assertFalse($run->events()->where('type', 'worker_submitted')->exists());

        // Without a code, the change that waits asks for one.
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'No code.'])->assertSee('Pass the task code');
        $this->tool('submit_change', $token, ['task' => $second, 'patch' => $this->workersChange(), 'summary' => 'New work.'])->assertSee('Received.');
    }

    public function test_work_on_a_stopped_change_is_never_handed_in_to_the_next_one()
    {
        $old = $this->startRun();
        $token = app(ConnectOwnTool::class)->handle($old->featureRequest->project);
        $code = $this->taskCode($token);

        // The owner stops it; the next change waits for the same tool.
        $old->update(['status' => RunStatus::Cancelled]);
        $next = app(StartRun::class)->handle(FeatureRequest::factory()->for($old->featureRequest->project)->create(['prompt' => 'Give teams a colour.']))->refresh();

        $this->tool('submit_change', $token, ['task' => $code, 'patch' => $this->workersChange(), 'summary' => 'Old work.'])
            ->assertSee('The owner stopped this change')
            ->assertSee('hand nothing back');
        $this->tool('try_change', $token, ['task' => $code, 'patch' => '', 'command' => ['php', 'artisan', 'about']])->assertSee('The owner stopped this change');
        $this->assertFalse($next->events()->where('type', 'worker_submitted')->exists());

        // A code from nowhere is not known.
        $this->tool('submit_change', $token, ['task' => 'nosuchcode', 'patch' => $this->workersChange(), 'summary' => 'Guess.'])->assertSee('not known here');
        $this->assertFalse($next->events()->where('type', 'worker_submitted')->exists());
    }

    protected function taskCode(string $token): string
    {
        $text = (string) $this->tool('get_task', $token)->json('result.content.0.text');
        $this->assertSame(1, preg_match('/Your task code is (\w+)\./', $text, $match));

        return $match[1];
    }

    public function test_the_worker_opens_the_app_with_its_change_in_a_browser_apart_from_the_owner()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('open_preview', $token)->assertSee('not running yet');

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        $preview = Preview::factory()->ready()->create(['project_id' => $run->featureRequest->project_id, 'feature_request_id' => $run->feature_request_id]);
        Http::fake(['*' => Http::response('ok')]);
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
        Http::fake(['*' => Http::response('ok')]);

        $this->mock(MakePreviewPerson::class, fn (MockInterface $mock) => $mock->shouldReceive('in')->once()
            ->andReturn(['id' => '12', 'name' => 'Ada', 'email' => 'ada@example.test']));
        $this->mock(SignInToPreview::class, fn (MockInterface $mock) => $mock->shouldReceive('cookie')
            ->once()->with(Mockery::any(), '12')->andReturn(['name' => 'app_session', 'value' => 'sealed', 'minutes' => 120]));

        $this->tool('open_preview', $token, ['person' => 'new'])
            ->assertSee('signed in as a test person the app made: ada@example.test');
    }

    public function test_a_preview_that_stopped_on_our_side_starts_again_instead_of_giving_a_dead_link()
    {
        Queue::fake([VerifyFeatureRequest::class, StartPreview::class]);
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        $dead = Preview::factory()->ready()->create(['project_id' => $run->featureRequest->project_id, 'feature_request_id' => $run->feature_request_id]);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->tool('open_preview', $token)
            ->assertSee('This is our fault: the app with your change had stopped on our side.')
            ->assertDontSee('grant=');

        $this->assertNotSame(PreviewStatus::Ready, $dead->refresh()->status);
        Queue::assertPushed(StartPreview::class, fn (StartPreview $job) => $job->preview->isNot($dead));
    }

    public function test_a_person_the_app_cannot_sign_in_points_the_worker_to_a_test_person()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        Preview::factory()->ready()->create(['project_id' => $run->featureRequest->project_id, 'feature_request_id' => $run->feature_request_id]);
        Http::fake(['*' => Http::response('ok')]);

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

    public function test_a_worker_with_no_folder_makes_its_change_here_and_hands_it_back()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $config = ['builder.agents.workers.try_commands' => [['cat'], ['sh', '-c']]];

        $this->tool('list_files', $token, ['directory' => 'app/Models'])->assertSee('Team.php');
        $read = (string) $this->tool('read_file', $token, ['path' => 'app/Models/Team.php'])->json('result.content.0.text');
        [$head, $contents] = explode("\n\n", $read, 2);
        $sha = Str::after($head, 'sha256: ');

        $this->tool('write_file', $token, [
            'path' => 'app/Models/Team.php',
            'contents' => str_replace("public string \$name = 'Team';", "public string \$name = 'Team';\n\n    public ?string \$description = null;", $contents),
            'expected_sha256' => $sha,
            'doing' => 'Giving each team a description.',
        ])->assertDontSee('"isError":true', false);
        $this->tool('write_file', $token, ['path' => 'app/Made.php', 'contents' => "<?php\n"]);
        $this->tool('search_files', $token, ['query' => 'description'])->assertSee('Team.php');
        $this->assertTrue($run->events()->where('type', 'worker_progress')->where('data->text', 'Giving each team a description.')->exists());

        // A try runs on the change made here, and keeps what it writes.
        $this->tool('try_change', $token, ['command' => ['cat', 'app/Models/Team.php']], $config)->assertSee('$description = null');
        $this->tool('try_change', $token, ['command' => ['sh', '-c', 'echo made > app/Written.php']], $config)
            ->assertSee('now part of your change here')
            ->assertDontSee('+++ b/app/Written.php');

        $this->tool('submit_change', $token, ['summary' => 'Added a description.'])->assertSee('Received.');

        $patch = (string) $run->events()->where('type', 'worker_submitted')->sole()->data['patch'];
        $this->assertStringContainsString('+    public ?string $description = null;', $patch);
        $this->assertStringContainsString('+++ b/app/Made.php', $patch);
        $this->assertStringContainsString('+++ b/app/Written.php', $patch);
    }

    public function test_a_try_with_a_patch_leaves_the_change_made_here_as_it_was()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $config = ['builder.agents.workers.try_commands' => [['cat']]];

        $this->tool('write_file', $token, ['path' => 'app/Made.php', 'contents' => "<?php\n// made here\n"]);

        // The same change tried from a folder, where nothing was made.
        $this->tool('try_change', $token, ['patch' => $this->workersChange(), 'command' => ['cat', 'app/Made.php']], $config)
            ->assertDontSee('made here');

        $this->tool('read_file', $token, ['path' => 'app/Made.php'])->assertSee('made here');
        $this->tool('read_file', $token, ['path' => 'app/Models/Team.php'])->assertDontSee('description');
    }

    public function test_a_write_too_large_to_hand_back_is_not_kept_and_the_change_stays_as_it_was()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $small = ['builder.agents.workers.max_patch_kb' => 1];
        $this->tool('write_file', $token, ['path' => 'app/Made.php', 'contents' => "<?php\n// made here\n"]);

        $this->tool('write_file', $token, ['path' => 'app/Huge.php', 'contents' => "<?php\n".str_repeat("// line\n", 400)], $small)
            ->assertSee('That write was not kept: with it, your change would be larger than 1 KB')
            ->assertSee('Your change is as it was before.');

        // What it reads is what it would hand in.
        $this->tool('read_file', $token, ['path' => 'app/Huge.php'], $small)->assertSee('does not exist');
        $this->tool('read_file', $token, ['path' => 'app/Made.php'], $small)->assertSee('made here');
        $this->tool('submit_change', $token, ['summary' => 'Made a file.'], $small)->assertSee('Received.');
        $patch = (string) $run->events()->where('type', 'worker_submitted')->sole()->data['patch'];
        $this->assertStringContainsString('+++ b/app/Made.php', $patch);
        $this->assertStringNotContainsString('Huge.php', $patch);
    }

    public function test_a_smaller_write_after_one_too_large_is_kept()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $small = ['builder.agents.workers.max_patch_kb' => 1];

        $this->tool('write_file', $token, ['path' => 'app/Huge.php', 'contents' => "<?php\n".str_repeat("// line\n", 400)], $small)->assertSee('not kept');
        $this->tool('write_file', $token, ['path' => 'app/Huge.php', 'contents' => "<?php\n// short\n"], $small)->assertDontSee('"isError":true', false);

        $this->assertStringEndsWith("<?php\n// short\n", (string) $this->tool('read_file', $token, ['path' => 'app/Huge.php'], $small)->json('result.content.0.text'));
    }

    public function test_what_a_try_writes_too_large_to_hand_back_is_not_kept()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $config = ['builder.agents.workers.max_patch_kb' => 1, 'builder.agents.workers.try_commands' => [['sh', '-c']]];
        $this->tool('write_file', $token, ['path' => 'app/Made.php', 'contents' => "<?php\n// made here\n"]);

        $this->tool('try_change', $token, ['command' => ['sh', '-c', 'seq 1 2000 > app/Numbers.txt']], $config)
            ->assertSee('What the command wrote was not kept');

        $this->tool('read_file', $token, ['path' => 'app/Numbers.txt'], $config)->assertSee('does not exist');
        $this->tool('read_file', $token, ['path' => 'app/Made.php'], $config)->assertSee('made here');
    }

    public function test_a_worker_with_no_folder_hears_what_it_may_not_do_here()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['summary' => 'Nothing yet.'])->assertSee('Your change is empty.');
        $this->tool('write_file', $token, ['path' => '.env', 'contents' => "APP_KEY=\n"])->assertSee('is protected and cannot be changed');
        $this->tool('write_file', $token, ['path' => '../outside.php', 'contents' => "<?php\n"])->assertSee('must stay inside the project');
        $this->tool('write_file', $token, ['path' => 'app/Models/Team.php', 'contents' => "<?php\n"])->assertSee('read it and pass its sha256');
        $this->tool('read_file', $token, ['path' => 'app/Missing.php'])->assertSee('does not exist');

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.']);
        $this->tool('read_file', $token, ['path' => 'app/Models/Team.php'])->assertSee('The files open only while the change waits for you.');
        $this->assertSame(0, $run->events()->where('type', 'worker_submitted')->where('data->summary', 'Nothing yet.')->count());
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
        // A worker of the owner's reads the rules with the task: they are
        // written to be read.
        $this->assertStringContainsString('## How to work', $task);
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
            'review' => ['approved' => false, 'summary' => '', 'preserved' => [], 'verified' => [], 'coverage' => [], 'findings' => [
                ['severity' => 'blocking', 'summary' => 'The description is never saved.', 'file' => 'app/Models/Team.php'],
                ['severity' => 'minor', 'summary' => 'A comment is long.', 'file' => null],
            ], 'changes' => [], 'classification' => ['requested' => [], 'may_also_affect' => [], 'unexpected' => [], 'unclaimed' => [], 'context_updates' => [], 'targets' => [], 'notes_behind' => [], 'observed' => null]],
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

    public function test_a_run_whose_worker_never_handed_the_change_back_stops_once_its_connection_runs_out()
    {
        $run = $this->startRun();
        app(GrantWorkerAccess::class)->handle($run);
        Queue::fake([ExecuteRun::class]);

        $this->travel((int) config('builder.agents.workers.minutes') - 1)->minutes();
        $this->artisan('runs:reconcile')->assertSuccessful();
        $this->assertSame(RunStatus::Implementing, $run->refresh()->status, 'The connection is still open.');

        $this->travel(2)->minutes();
        $this->artisan('runs:reconcile')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('Your own coding tool did not hand this change back before its connection ran out, so I stopped it. Your app is as it was, and you can hand the change to your tool again.', $run->error);
        $this->assertSame(StopReason::WorkerLapsed, $run->stop_reason);
        $this->assertTrue(HandChangeToOwner::available($run->featureRequest->refresh()), 'The owner can hand it to their tool again.');
        Queue::assertNothingPushed();
    }

    public function test_a_worker_gets_the_tests_written_first_and_a_change_that_keeps_them_goes_on()
    {
        $this->writeTestsFirst();
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        // Its copy does not have them, so the task gives them whole.
        $task = $this->tool('get_task', $token)->json('result.content.0.text');
        $this->assertStringContainsString('add each file below to your copy exactly as written', $task);
        $this->assertStringContainsString('1. tests/Feature/TeamDescriptionTest.php: a team keeps its description', $task);
        $this->assertStringContainsString("### tests/Feature/TeamDescriptionTest.php\n\n````php\n".rtrim(self::WRITTEN)."\n````", $task);

        $this->tool('submit_change', $token, ['patch' => $this->workersChange().$this->addingTest(self::WRITTEN), 'summary' => 'Added a description.'])->assertSee('Received.');

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status, json_encode($run->events()->pluck('data', 'type')));
        $this->assertStringContainsString("+test('a team keeps its description', fn () => expect(true)->toBeTrue());", (string) $run->featureRequest->patch);
        $this->assertSame(0, $run->events()->where('type', 'written_tests_restored')->count());
        $this->assertSame(0, $run->repairs);
    }

    public function test_a_worker_gets_no_written_tests_while_writing_them_first_is_off()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        TestWriter::assertNeverPrompted();
        $this->assertSame([], $run->plan['written_tests']);
        $this->tool('get_task', $token)->assertDontSee('Tests already written');

        // Without written tests, the tests it hands back are all its own.
        $this->tool('submit_change', $token, ['patch' => $this->workersChange().$this->addingTest(self::BENT), 'summary' => 'Added a description.'])->assertSee('Received.');
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);
    }

    public function test_a_written_test_the_worker_left_out_is_put_back_and_its_check_failing_sends_the_change_back()
    {
        $this->writeTestsFirst();
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.'])->assertSee('Received.');

        // Our copy cannot tell left out from not added, so it is put back and checked.
        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['tests/Feature/TeamDescriptionTest.php'], $run->events()->where('type', 'written_tests_restored')->sole()->data['paths']);
        $this->assertSame(0, $run->events()->where('type', 'status')->where('data->reason', 'written_tests_changed')->count());
        $this->assertStringContainsString("+test('a team keeps its description', fn () => expect(true)->toBeTrue());", (string) $run->featureRequest->patch);

        // Its test failing is a failed check like any other.
        $this->verify($run, VerificationStatus::Failed);
        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $this->tool('check_status', $token)->assertSee('The checks found problems.');
    }

    public function test_a_worker_that_changes_a_written_test_is_sent_back_with_the_test_and_then_stopped()
    {
        $this->writeTestsFirst();
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['patch' => $this->workersChange().$this->addingTest(self::BENT), 'summary' => 'Skipped the slow test.'])->assertSee('Received.');

        $run->refresh();
        $this->assertSame(RunStatus::Implementing, $run->status);
        $this->assertSame(1, $run->repairs);
        $this->assertSame([['file' => 'tests/Feature/TeamDescriptionTest.php', 'name' => 'a team keeps its description']], $run->events()->where('type', 'status')->where('data->reason', 'written_tests_changed')->sole()->data['tests']);
        $this->assertStringContainsString('You changed the test "a team keeps its description" in tests/Feature/TeamDescriptionTest.php, which was written before the change.', $this->tool('get_task', $token)->json('result.content.0.text'));
        $this->assertSame(0, $run->verifications()->count(), 'A change that bent its tests is not checked.');

        // With no tries left, it stops and says which test.
        config(['builder.construction.budgets.repairs' => 1]);
        $this->tool('submit_change', $token, ['patch' => $this->workersChange().$this->addingTest(self::BENT), 'summary' => 'Skipped it again.'])->assertSee('Received.');

        $run->refresh();
        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame(1, $run->events()->where('type', 'status')->where('data->reason', 'written_tests_changed')->where('data->to', RunStatus::NeedsUserDecision->value)->count());
        $this->assertStringContainsString('changed the tests written to check it, so the change proves nothing: a team keeps its description.', (string) $run->error);
    }

    public function test_a_written_test_the_worker_says_is_wrong_is_corrected_instead_of_sent_back()
    {
        $file = 'tests/Feature/TeamDescriptionTest.php';
        $corrected = "<?php\n\ntest('a team keeps its description', fn () => expect('About')->toBeString());\n";
        config(['builder.verification.written_first.enabled' => true]);
        TestWriter::fake([
            ['files' => [['path' => $file, 'contents' => self::WRITTEN]], 'tests' => [['item' => 1, 'file' => $file, 'name' => 'a team keeps its description']]],
            ['files' => [['path' => $file, 'contents' => $corrected]], 'tests' => [['item' => 1, 'file' => $file, 'name' => 'a team keeps its description']]],
        ]);
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['patch' => $this->workersChange().$this->addingTest(self::BENT), 'summary' => "Added a description.\n\nTEST WRONG {$file} :: a team keeps its description: the app has no about text."])->assertSee('Received.');

        // Its own version is not kept: the corrected one is checked.
        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(0, $run->repairs);
        $this->assertSame(0, $run->events()->where('type', 'status')->where('data->reason', 'written_tests_changed')->count());
        $this->assertSame('coder', $run->events()->where('type', 'written_test_rewritten')->sole()->data['by']);
        $this->assertStringContainsString("+test('a team keeps its description', fn () => expect('About')->toBeString());", (string) $run->featureRequest->patch);
    }

    /**
     * Have tests written from the plan before the change, one for its item.
     */
    protected function writeTestsFirst(): void
    {
        config(['builder.verification.written_first.enabled' => true]);
        TestWriter::fake([[
            'files' => [['path' => 'tests/Feature/TeamDescriptionTest.php', 'contents' => self::WRITTEN]],
            'tests' => [['item' => 1, 'file' => 'tests/Feature/TeamDescriptionTest.php', 'name' => 'a team keeps its description']],
        ]]);
    }

    /**
     * A patch that adds the test file with the given contents.
     */
    protected function addingTest(string $contents): string
    {
        $lines = explode("\n", rtrim($contents, "\n"));

        return "diff --git a/tests/Feature/TeamDescriptionTest.php b/tests/Feature/TeamDescriptionTest.php\nnew file mode 100644\n--- /dev/null\n+++ b/tests/Feature/TeamDescriptionTest.php\n@@ -0,0 +1,".count($lines)." @@\n".implode('', array_map(fn (string $line) => "+{$line}\n", $lines));
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
