<?php

namespace Tests\Feature\Evaluation;

use App\Ai\Agents\FeaturePlanner;
use App\Enums\AgentOutcomeStatus;
use App\Evaluation\Handoff;
use App\Evaluation\HandoffCodingAgent;
use App\Models\Workspace;
use App\Providers\EvaluationServiceProvider;
use App\Runs\Agents\AgentTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class HandoffTest extends TestCase
{
    use RefreshDatabase;

    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/builder-handoff-test-'.Str::lower(Str::random(8));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->directory));
    }

    public function test_a_request_is_written_and_its_response_returned()
    {
        $this->answerWith(['answer' => 42]);

        $response = (new Handoff($this->directory, 30))->ask('planner', ['prompt' => 'Plan this']);

        $this->assertSame(['answer' => 42], $response);

        $request = $this->onlyRequest();
        $this->assertSame('planner', $request['role']);
        $this->assertSame('Plan this', $request['prompt']);
        $this->assertStringEndsWith('.response.json', $request['response']);
    }

    public function test_requests_carry_the_labels_they_were_made_within()
    {
        $this->answerWith(['approved' => true]);

        Handoff::within(['task' => 'export', 'condition' => 'v2'], fn () => (new Handoff($this->directory, 30))->ask('generic-reviewer', ['prompt' => 'Review']));

        $this->assertSame(['task' => 'export', 'condition' => 'v2'], $this->onlyRequest()['context']);
    }

    public function test_no_response_in_time_is_an_error()
    {
        Sleep::fake(syncWithCarbon: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No response');

        (new Handoff($this->directory, 5))->ask('planner', ['prompt' => 'Plan this']);
    }

    public function test_the_coding_agent_hands_off_the_task_with_its_workspace_and_reports_the_outcome()
    {
        config(['workspaces.drivers.local.root' => '/srv/workspaces']);
        $this->answerWith(['status' => 'completed', 'summary' => 'Added the route.']);
        $workspace = Workspace::factory()->create(['driver' => 'local', 'driver_id' => 'workspace-abc']);

        $outcome = (new HandoffCodingAgent(new Handoff($this->directory, 30)))->run($workspace, new AgentTask('Build it', timeoutSeconds: 600));

        $this->assertSame(AgentOutcomeStatus::Completed, $outcome->status);
        $this->assertSame('Added the route.', $outcome->summary);
        $this->assertSame('/srv/workspaces/workspace-abc', $this->onlyRequest()['workspace']);
        $this->assertSame('Build it', $this->onlyRequest()['prompt']);
    }

    public function test_enabled_hand_offs_give_the_planner_its_instructions_and_schema()
    {
        config(['evaluation.handoff.path' => $this->directory, 'evaluation.handoff.timeout_seconds' => 30]);
        (new EvaluationServiceProvider($this->app))->boot();
        $this->answerWith(['summary' => 'A plan']);

        $response = FeaturePlanner::make()->prompt('Let owners delete teams');

        $request = $this->onlyRequest();
        $this->assertSame('planner', $request['role']);
        $this->assertSame('Let owners delete teams', $request['prompt']);
        $this->assertStringContainsString('You plan changes to a Laravel application', $request['instructions']);
        $this->assertContains('summary', $request['schema']['required']);
        $this->assertSame('A plan', $response['summary']);
    }

    public function test_hand_offs_are_refused_in_production()
    {
        config(['evaluation.handoff.path' => $this->directory]);
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        (new EvaluationServiceProvider($this->app))->boot();
    }

    /**
     * Answer the next hand-off request with the given response, as a
     * responder would, while the hand-off waits.
     *
     * @param  array<string, mixed>  $response
     */
    protected function answerWith(array $response): void
    {
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($response) {
            foreach (File::glob("{$this->directory}/*.request.json") as $file) {
                $request = json_decode(File::get($file), true);
                File::put($request['response'], json_encode($response));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function onlyRequest(): array
    {
        $files = File::glob("{$this->directory}/*.request.json");
        $this->assertCount(1, $files);

        return json_decode(File::get($files[0]), true);
    }
}
