<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\GrantWorkerAccess;
use App\Actions\Runs\TransitionRun;
use App\Enums\RunStatus;
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
            ->assertSee('How to work');

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
        app(TransitionRun::class)->handle($run, RunStatus::Failed);

        $this->assertSame(0, $run->tokens()->count(), 'A change that ended revokes its tokens.');
        $this->getTask($token)->assertUnauthorized();
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
            ))->toArray(),
        ]);
    }
}
