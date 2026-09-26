<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\SummarizeProjectTelemetry;
use App\Actions\Runs\RecordModelUsage;
use App\Enums\ModelRole;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Tests\TestCase;

class ProjectTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_cost_per_accepted_change_first_attempt_passes_and_unexpected_changes()
    {
        $project = Project::factory()->create();

        $accepted = $this->request($project, ['commit_sha' => 'abc', 'accepted_at' => now()]);
        $acceptedRun = $this->completedRun($accepted, repairs: 1, unexpected: []);
        $acceptedRun->recordEvent('model_call', ['role' => 'planner', 'input_tokens' => 1000, 'output_tokens' => 100, 'cost_usd' => 0.5]);
        $acceptedRun->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'claude', 'input_tokens' => 5000, 'output_tokens' => 900, 'cost_usd' => 1.25]);
        $this->verification($acceptedRun, VerificationStatus::Failed);
        $this->verification($acceptedRun, VerificationStatus::Passed);

        $abandoned = $this->request($project);
        $abandonedRun = $this->completedRun($abandoned, repairs: 0, unexpected: ['billing' => ['config/billing.php']]);
        $abandonedRun->recordEvent('model_call', ['role' => 'reviewer', 'input_tokens' => 400, 'output_tokens' => 40, 'cost_usd' => null]);
        $abandonedRun->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'input_tokens' => 100, 'output_tokens' => 10, 'cost_usd' => 0.25]);
        $this->verification($abandonedRun, VerificationStatus::Unverified);

        $telemetry = app(SummarizeProjectTelemetry::class)->handle($project);

        $this->assertSame(2, $telemetry['requests']);
        $this->assertSame(1, $telemetry['accepted']);
        $this->assertSame(2.0, $telemetry['cost_usd']);
        $this->assertSame(1, $telemetry['unpriced_calls']);
        $this->assertSame(2.0, $telemetry['cost_per_accepted_change_usd']);
        $this->assertSame(2, $telemetry['runs_verified']);
        $this->assertSame(1, $telemetry['first_attempt_passed']);
        $this->assertSame(1.0, $telemetry['repairs_before_acceptance']);
        $this->assertSame(2, $telemetry['reviewed']);
        $this->assertSame(1, $telemetry['with_unexpected_changes']);
        $this->assertSame(6500, $telemetry['input_tokens']);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('telemetry.accepted', 1)->where('telemetry.cost_per_accepted_change_usd', 2));
    }

    public function test_model_calls_are_priced_from_the_configured_prices_and_unknown_models_are_left_unpriced()
    {
        config(['builder.prices' => ['planner.model-1' => ['input' => 3, 'output' => 15]]]);
        $run = $this->completedRun($this->request(Project::factory()->create()), repairs: 0, unexpected: []);

        app(RecordModelUsage::class)->handle($run, ModelRole::Planner, new AgentResponse('call-1', 'plan', new TextUsage(1000, 100), new Meta('anthropic', 'planner.model-1')));
        app(RecordModelUsage::class)->handle($run, ModelRole::Reviewer, new AgentResponse('call-2', 'review', new TextUsage(1000, 100), new Meta('openai', 'unknown')));

        $this->assertSame([0.0045, null], $run->events()->where('type', 'model_call')->orderBy('sequence')->get()->pluck('data.cost_usd')->all());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function request(Project $project, array $attributes = []): FeatureRequest
    {
        return FeatureRequest::factory()->generated()->for($project)->create($attributes);
    }

    /**
     * @param  array<string, list<string>>  $unexpected
     */
    protected function completedRun(FeatureRequest $request, int $repairs, array $unexpected): Run
    {
        return Run::factory()->for($request)->create([
            'status' => RunStatus::Completed,
            'repairs' => $repairs,
            'review' => ['approved' => true, 'summary' => 'ok', 'findings' => [], 'changes' => [], 'classification' => [
                'requested' => [], 'may_also_affect' => [], 'unexpected' => $unexpected, 'unclaimed' => [], 'context_updates' => [], 'targets' => [],
            ]],
        ]);
    }

    protected function verification(Run $run, VerificationStatus $status): Verification
    {
        return $run->featureRequest->verifications()->create(['run_id' => $run->id, 'status' => $status]);
    }
}
