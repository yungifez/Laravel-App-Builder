<?php

namespace Tests\Feature\Runs;

use App\Actions\Projects\ConnectOwnTool;
use App\Actions\Projects\DisconnectOwnTool;
use App\Actions\Runs\GrantWorkerAccess;
use App\Actions\Runs\TransitionRun;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Models\Project;
use App\Models\Run;
use App\Runs\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WorkerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workers_token_opens_its_own_changes_brief_and_the_call_is_logged()
    {
        $run = $this->plannedRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->getTask($token)
            ->assertOk()
            ->assertSee('Members can book a class.')
            ->assertSee('A member can book a class with room left.')
            ->assertSee('How to work')
            ->assertSee('so the lock file changes with it');

        $this->assertSame(['tool' => 'get_task'], $run->events()->where('type', 'worker_query')->sole()->data);
    }

    public function test_the_brief_waits_while_the_change_is_still_planned()
    {
        $run = Run::factory()->implementing()->create();

        $this->getTask(app(GrantWorkerAccess::class)->handle($run))
            ->assertOk()
            ->assertSee('still being planned');
    }

    public function test_only_a_live_token_for_a_change_that_has_not_ended_gets_in()
    {
        $run = $this->plannedRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->getTask(null)->assertUnauthorized();
        $this->getTask('1|not-a-token')->assertUnauthorized();

        // The owner's own session opens nothing here.
        $this->actingAs($run->featureRequest->project->owner)->getTask(null)->assertUnauthorized();

        $this->travel((int) config('builder.agents.workers.minutes') + 1)->minutes();
        $this->getTask($token)->assertUnauthorized();
        $this->travelBack();

        $this->getTask($token)->assertOk();
        app(TransitionRun::class)->handle($run, RunStatus::Failed, details: ['reason' => StopReason::WorkerStopped]);

        // A change that ended still says so, for a short while only.
        $this->getTask($token)->assertOk()->assertSee('This change has ended')->assertDontSee('Members can book a class.');
        $this->travel((int) config('builder.agents.workers.ended_minutes') + 1)->minutes();
        $this->getTask($token)->assertUnauthorized();
    }

    public function test_a_tool_hears_that_its_change_passed_after_the_change_ended()
    {
        $run = $this->plannedRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $run->update(['status' => RunStatus::Reviewing]);

        app(TransitionRun::class)->handle($run, RunStatus::Completed);

        $this->useTool('check_status', $token)->assertOk()->assertSee('passed its checks and review');
        $this->getTask($token)->assertOk()->assertSee('This change has ended')->assertDontSee('Members can book a class.');
        $this->assertTrue($run->tokens()->sole()->expires_at->lte(now()->addMinutes((int) config('builder.agents.workers.ended_minutes'))));
    }

    public function test_a_tool_on_a_stopped_change_may_only_ask_how_it_ended()
    {
        $run = $this->plannedRun();
        $run->update(['driver' => 'worker']);
        $token = app(GrantWorkerAccess::class)->handle($run);

        app(TransitionRun::class)->handle($run, RunStatus::Cancelling);
        app(TransitionRun::class)->handle($run, RunStatus::Cancelled);

        $this->useTool('check_status', $token)->assertOk()->assertSee('The owner stopped this change.');
        $patch = "diff --git a/a.txt b/a.txt\nnew file mode 100644\n--- /dev/null\n+++ b/a.txt\n@@ -0,0 +1 @@\n+a\n";
        $this->useTool('submit_change', $token, ['patch' => $patch, 'summary' => 'Added a.'])->assertSee('not waiting for a patch now')->assertDontSee('Received.');
        $this->useTool('try_change', $token, ['command' => ['php', 'artisan', 'test']])->assertSee('Commands run only while the change waits for you.');
        $this->useTool('write_file', $token, ['path' => 'a.txt', 'content' => "a\n"])->assertSee('The files open only while the change waits for you.');
        $this->useTool('open_preview', $token)->assertSee('not running');
        $this->assertSame(0, $run->events()->where('type', 'worker_submitted')->count());
    }

    public function test_a_token_that_ends_sooner_is_never_lengthened_and_a_disconnect_shuts_out_at_once()
    {
        $run = $this->plannedRun();
        $token = app(GrantWorkerAccess::class)->handle($run);
        $soon = now()->addMinutes(5)->startOfSecond();
        $run->tokens()->update(['expires_at' => $soon]);

        app(TransitionRun::class)->handle($run, RunStatus::Failed, details: ['reason' => StopReason::WorkerStopped]);

        $this->assertTrue($run->tokens()->sole()->expires_at->equalTo($soon));

        $project = Project::factory()->create();
        $connected = app(ConnectOwnTool::class)->handle($project);
        $this->getTask($connected)->assertOk();
        app(DisconnectOwnTool::class)->handle($project);
        $this->getTask($connected)->assertUnauthorized();
    }

    public function test_a_token_opens_only_its_own_change()
    {
        $mine = $this->plannedRun('Members can book a class.');
        $theirs = $this->plannedRun('Trainers can cancel a class.');

        $this->getTask(app(GrantWorkerAccess::class)->handle($mine))
            ->assertSee('Members can book a class.')
            ->assertDontSee('Trainers can cancel a class.');

        $this->assertSame(0, $theirs->events()->where('type', 'worker_query')->count());
    }

    public function test_a_worker_is_held_to_its_calls_a_minute()
    {
        config(['builder.agents.workers.per_minute' => 2]);
        $token = app(GrantWorkerAccess::class)->handle($this->plannedRun());

        $this->getTask($token)->assertOk();
        $this->getTask($token)->assertOk();
        $this->getTask($token)->assertTooManyRequests();
    }

    /**
     * Call the get_task tool as a worker's MCP client would.
     */
    /**
     * Call one of the change's tools as a worker's MCP client would.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function useTool(string $tool, string $token, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(route('mcp.task'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
    }

    protected function getTask(?string $token): TestResponse
    {
        return $this->withHeaders($token === null ? [] : ['Authorization' => "Bearer {$token}"])
            ->postJson(route('mcp.task'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_task', 'arguments' => []]]);
    }

    protected function plannedRun(string $summary = 'Members can book a class.'): Run
    {
        return Run::factory()->implementing()->create([
            'plan' => (new Plan(
                summary: $summary,
                acceptanceCriteria: ['A member can book a class with room left.'],
                tasks: ['Add booking.'],
                cases: [
                    ['criterion' => 1, 'kind' => 'base', 'says' => 'A member books a class with two places left.', 'none' => null],
                    ['criterion' => 1, 'kind' => 'alternate', 'says' => 'A member books the last place.', 'none' => null],
                    ['criterion' => 1, 'kind' => 'exception', 'says' => 'A member cannot book a full class.', 'none' => null],
                ],
            ))->toArray(),
        ]);
    }
}
