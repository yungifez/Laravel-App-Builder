<?php

namespace Tests\Feature\Features;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Jobs\DecideFeatureRequest;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkYourselfTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ExecuteRun::class, DecideFeatureRequest::class]);
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner, 'owner')->create(['name' => 'Bright Cleaning']);
    }

    public function test_the_owner_takes_over_a_change_we_are_making_and_gets_a_connection_once()
    {
        $ours = $this->change(RunStatus::Planning, FeatureRequestStatus::Generating);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'change' => $ours->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('change.featureRequest.can_work_yourself', true)
                ->where('change.run.yours', null));

        $response = $this->post(route('feature-requests.worker.store', $ours));

        $theirs = $this->project->featureRequests()->latest('id')->firstOrFail();
        $run = $theirs->latestRun()->firstOrFail();

        $response->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $theirs->uuid]));
        $this->assertSame($ours->id, $theirs->retry_of_id);
        $this->assertSame('worker', $run->driver);
        $this->assertSame(RunStatus::Cancelled, $ours->latestRun()->firstOrFail()->status);
        Queue::assertPushed(ExecuteRun::class, fn (ExecuteRun $job) => $job->run->is($run));

        $token = session('inertia.flash_data.worker.token');
        $response->assertInertiaFlash('worker.run', $run->uuid);
        $this->assertIsString($token);
        $this->assertSame(1, $run->tokens()->count());
        $this->tool('check_status', $token)->assertOk();

        // The connection shows from the hand-over, while the change is
        // still planned, so it is never lost to a reload.
        $this->flushHeaders()->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'change' => $theirs->uuid]))
            ->assertInertia(fn (Assert $page) => $page->where('change.run.yours.waiting', true));
    }

    public function test_the_thread_says_how_to_connect_while_it_waits_for_their_change()
    {
        $theirs = $this->change(RunStatus::Implementing, FeatureRequestStatus::Generating, 'worker');

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'change' => $theirs->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('change.run.yours.waiting', true)
                ->where('change.run.yours.address', route('mcp.task'))
                ->where('change.run.yours.name', 'bright-cleaning'));
    }

    public function test_connecting_again_closes_the_earlier_connection_and_keeps_the_change()
    {
        $theirs = $this->change(RunStatus::Implementing, FeatureRequestStatus::Generating, 'worker');
        $run = $theirs->latestRun()->firstOrFail();

        $this->actingAs($this->owner)->post(route('feature-requests.worker.store', $theirs));
        $first = session('inertia.flash_data.worker.token');

        $this->post(route('feature-requests.worker.store', $theirs))
            ->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $theirs->uuid]));
        $second = session('inertia.flash_data.worker.token');

        $this->assertSame(1, $this->project->featureRequests()->count());
        $this->assertSame(1, $run->tokens()->count());
        $this->tool('check_status', $first)->assertUnauthorized();
        $this->tool('check_status', $second)->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_a_stopped_change_can_be_written_by_the_owner()
    {
        $stopped = $this->change(RunStatus::Failed, FeatureRequestStatus::Failed);

        $this->actingAs($this->owner)
            ->post(route('feature-requests.worker.store', $stopped))
            ->assertSessionHasNoErrors();

        $this->assertSame('worker', $this->project->featureRequests()->latest('id')->firstOrFail()->latestRun()->firstOrFail()->driver);
    }

    public function test_a_made_change_or_one_waiting_for_an_answer_is_not_handed_over()
    {
        $made = $this->change(RunStatus::Completed, FeatureRequestStatus::Generated);
        $asking = $this->change(RunStatus::NeedsUserDecision, FeatureRequestStatus::Generating, attributes: ['question' => ['text' => 'Who can see it?', 'why' => '', 'options' => ['Everyone', 'Members'], 'recommended' => 'Members']]);

        foreach ([$made, $asking] as $change) {
            $this->actingAs($this->owner)
                ->post(route('feature-requests.worker.store', $change))
                ->assertSessionHasErrors('worker');
        }

        $this->assertSame(2, $this->project->featureRequests()->count());
        Queue::assertNothingPushed();
    }

    public function test_only_the_owner_can_hand_a_change_over()
    {
        $ours = $this->change(RunStatus::Planning, FeatureRequestStatus::Generating);

        $this->actingAs(User::factory()->create())
            ->post(route('feature-requests.worker.store', $ours))
            ->assertForbidden();

        $this->assertSame(0, Run::query()->where('driver', 'worker')->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function change(RunStatus $status, FeatureRequestStatus $requestStatus, string $driver = 'scripted', array $attributes = []): FeatureRequest
    {
        $featureRequest = FeatureRequest::factory()->for($this->project)->create(['status' => $requestStatus, 'prompt' => 'Give teams a description.']);
        Run::factory()->for($featureRequest)->create(['status' => $status, 'driver' => $driver] + $attributes);

        return $featureRequest;
    }

    /**
     * Call one of the change's tools as the owner's Claude Code or Codex would.
     */
    protected function tool(string $tool, string $token): TestResponse
    {
        auth()->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(route('mcp.task'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]]);
    }
}
