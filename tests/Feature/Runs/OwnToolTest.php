<?php

namespace Tests\Feature\Runs;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Projects\ConnectOwnTool;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DisconnectOwnTool;
use App\Actions\Runs\StartRun;
use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Projects\ProjectRepository;
use App\Runs\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;
use ZipArchive;

class OwnToolTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    public function test_once_connected_the_owners_tool_writes_every_new_change()
    {
        Queue::fake();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.own-tool.store', $project))
            ->assertRedirect();
        $this->assertTrue(ConnectOwnTool::connected($project));

        $run = app(StartRun::class)->handle(FeatureRequest::factory()->for($project)->create());

        $this->assertSame('worker', $run->driver);
        $this->assertTrue($run->events()->where('type', 'handed_to_owner')->exists());
    }

    public function test_the_tool_gets_the_oldest_waiting_change_of_its_own_app_with_its_code()
    {
        $project = Project::factory()->create();
        $token = app(ConnectOwnTool::class)->handle($project);

        $this->getTask($token)->assertOk()->assertSee('No change waits for you now.');

        $this->waitingRun(Project::factory()->create(), 'Trainers can cancel a class.');
        $first = $this->waitingRun($project, 'Members can book a class.');
        $this->waitingRun($project, 'Members can cancel a booking.');

        $brief = (string) $this->getTask($token)->assertOk()->json('result.content.0.text');

        $this->assertStringContainsString('Members can book a class.', $brief);
        $this->assertStringNotContainsString('Members can cancel a booking.', $brief);
        $this->assertStringNotContainsString('Trainers can cancel a class.', $brief);
        $this->assertStringContainsString('/worker-code/'.$first->uuid.'?', $brief);
    }

    public function test_a_question_never_reaches_the_owners_tool()
    {
        $project = Project::factory()->create();
        $token = app(ConnectOwnTool::class)->handle($project);
        $run = Run::factory()->for(FeatureRequest::factory()->for($project))->create(['driver' => 'worker', 'status' => RunStatus::Planning]);

        // While our planner works out whether it is only a question, the
        // tool is not offered it and the owner is not told it writes it.
        $this->getTask($token)->assertOk()->assertSee('No change waits for you now.');
        $this->assertFalse(app(DescribeFeatureRequest::class)->handle($run->featureRequest)['run']['yours']['waiting']);

        // A question is answered from the plan and never built.
        $run->update(['status' => RunStatus::Completed, 'plan' => (new Plan(summary: 'How booking works.', answer: 'Members book from the class page.'))->toArray()]);
        $this->getTask($token)->assertOk()->assertSee('No change waits for you now.');

        // Something to build reaches it once planned.
        $this->waitingRun($project);
        $this->getTask($token)->assertOk()->assertSee('Members can book a class.');
    }

    public function test_the_start_commit_works_without_a_git_identity_or_signing()
    {
        $project = Project::factory()->create();
        $token = app(ConnectOwnTool::class)->handle($project);
        $this->waitingRun($project);

        $brief = (string) $this->getTask($token)->json('result.content.0.text');

        // Without a commit there is no HEAD to diff the change against.
        $this->assertStringContainsString('git -c user.name=start -c user.email=start@localhost -c commit.gpgsign=false commit -qm start', $brief);
        $this->assertStringNotContainsString('&& git commit', $brief);
    }

    public function test_a_fix_keeps_working_in_the_folder_with_the_first_try()
    {
        $project = Project::factory()->create();
        $token = app(ConnectOwnTool::class)->handle($project);
        $this->waitingRun($project)->update(['feedback' => ['details' => ['The booking test failed.']]]);

        $brief = (string) $this->getTask($token)->json('result.content.0.text');

        $this->assertStringContainsString('The booking test failed.', $brief);
        $this->assertStringContainsString('Keep working in the folder you made for this change', $brief);
        $this->assertStringNotContainsString('git init', $brief);
    }

    public function test_the_code_link_gives_the_code_the_change_starts_from_and_only_when_signed()
    {
        $owner = User::factory()->create();
        $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource(['README.md' => "Acme\n"]), draftNotes: false);
        app(ProjectRepository::class)->import($project);
        $run = $this->waitingRun($project);
        $token = app(ConnectOwnTool::class)->handle($project);

        preg_match('#http\S+/worker-code/\S+?(?=\s|\(|$)#', (string) $this->getTask($token)->json('result.content.0.text'), $link);
        $this->assertNotEmpty($link);

        $this->get($link[0])->assertOk()->assertDownload('acme.zip');
        $this->get(route('worker-code.show', $run))->assertForbidden();
    }

    public function test_the_code_of_a_follow_up_holds_the_earlier_change_not_kept_yet()
    {
        $owner = User::factory()->create();
        $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource(['README.md' => "Acme\n"]), draftNotes: false);
        $base = app(ProjectRepository::class)->import($project);
        $earlier = FeatureRequest::factory()->for($project)->create([
            'base_revision' => $base,
            'patch' => "diff --git a/booking.txt b/booking.txt\nnew file mode 100644\n--- /dev/null\n+++ b/booking.txt\n@@ -0,0 +1 @@\n+Book a class\n",
        ]);
        $run = Run::factory()->implementing()->for(FeatureRequest::factory()->for($project)->create(['parent_id' => $earlier->id, 'base_revision' => $base]))->create(['driver' => 'worker']);

        $response = $this->get(URL::temporarySignedRoute('worker-code.show', now()->addHour(), ['run' => $run]))->assertOk();
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());

        $this->assertSame("Book a class\n", $zip->getFromName('acme/booking.txt'));
        $this->assertSame("Acme\n", $zip->getFromName('acme/README.md'));

        $this->assertSame($base, app(ProjectRepository::class)->head($project), 'The app itself stays as it was.');
    }

    public function test_after_disconnecting_the_tool_is_shut_out_and_new_changes_are_ours()
    {
        Queue::fake();
        $project = Project::factory()->create();
        $token = app(ConnectOwnTool::class)->handle($project);

        $this->actingAs($project->owner)->delete(route('projects.own-tool.destroy', $project))->assertRedirect();

        $this->getTask($token)->assertUnauthorized();
        $this->assertNotSame('worker', app(StartRun::class)->handle(FeatureRequest::factory()->for($project)->create())->driver);
    }

    public function test_after_disconnecting_the_changes_waiting_for_the_tool_are_ours_with_their_plan()
    {
        Queue::fake();
        $project = Project::factory()->create();
        app(ConnectOwnTool::class)->handle($project);
        $waiting = $this->waitingRun($project);
        $queued = Run::factory()->for(FeatureRequest::factory()->for($project))->create(['driver' => 'worker', 'status' => RunStatus::Queued]);
        $stopped = $this->waitingRun($project, 'Members can cancel a booking.');
        $stopped->recordEvent('worker_submitted', ['patch' => 'diff', 'summary' => 'Done.']);
        $stopped->update(['status' => RunStatus::NeedsUserDecision]);
        $other = $this->waitingRun(Project::factory()->create());

        $this->actingAs($project->owner)->delete(route('projects.own-tool.destroy', $project))->assertRedirect();

        foreach ([$waiting, $queued, $stopped] as $run) {
            $run->refresh();
            $this->assertNotSame('worker', $run->driver);
            $this->assertTrue($run->events()->where('type', 'handed_back')->exists());
        }

        $this->assertSame('Members can book a class.', $waiting->plan['summary']);
        $this->assertSame(RunStatus::NeedsUserDecision, $stopped->status, 'Keep trying and Start over then use our coder');
        // Only the change waiting for a patch needs our coder started.
        Queue::assertPushed(ExecuteRun::class, 1);
        Queue::assertPushed(ExecuteRun::class, fn (ExecuteRun $job) => $job->run->is($waiting));
        $this->assertSame('worker', $other->refresh()->driver, 'another app keeps its tool');
    }

    public function test_a_change_the_tool_already_handed_back_is_left_to_finish()
    {
        Queue::fake();
        $project = Project::factory()->create();
        app(ConnectOwnTool::class)->handle($project);
        $applying = $this->waitingRun($project);
        $applying->recordEvent('worker_submitted', ['patch' => 'diff', 'summary' => 'Done.']);
        $verifying = $this->waitingRun($project, 'Members can cancel a booking.');
        $verifying->update(['status' => RunStatus::Verifying]);

        $this->assertSame(0, app(DisconnectOwnTool::class)->handle($project));

        $this->assertSame(['worker', 'worker'], [$applying->refresh()->driver, $verifying->refresh()->driver]);
        $this->assertFalse(ConnectOwnTool::connected($project));
        Queue::assertNothingPushed();
    }

    public function test_only_people_who_can_change_the_app_disconnect_its_tool()
    {
        $project = Project::factory()->create();
        app(ConnectOwnTool::class)->handle($project);
        $waiting = $this->waitingRun($project);

        $this->actingAs(User::factory()->create())->delete(route('projects.own-tool.destroy', $project))->assertForbidden();

        $this->assertTrue(ConnectOwnTool::connected($project));
        $this->assertSame('worker', $waiting->refresh()->driver);
    }

    public function test_only_people_who_can_change_the_app_connect_a_tool()
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())->post(route('projects.own-tool.store', $project))->assertForbidden();
        $this->assertFalse(ConnectOwnTool::connected($project));
    }

    /**
     * Call the get_task tool as the owner's MCP client would.
     */
    protected function getTask(string $token): TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(route('mcp.task'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_task', 'arguments' => []]]);
    }

    protected function waitingRun(Project $project, string $summary = 'Members can book a class.'): Run
    {
        return Run::factory()->implementing()->for(FeatureRequest::factory()->for($project))->create([
            'driver' => 'worker',
            'plan' => (new Plan(summary: $summary, acceptanceCriteria: ['It works.'], tasks: ['Do it.']))->toArray(),
        ]);
    }
}
