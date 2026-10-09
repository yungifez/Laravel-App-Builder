<?php

namespace Tests\Feature\Publishing;

use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\DeploymentStatus;
use App\Enums\StopReason;
use App\Jobs\VerifyFeatureRequest;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Mockery\MockInterface;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * A new version that went online but does not work ends in a fix, not in
 * sending the same version again. What its address answered goes to the
 * builder; the owner reads only their own plain ask.
 */
class OnlineCheckFixTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.publishing.confirm.timeout' => 10]);
        $this->project = Project::factory()->create();
    }

    public function test_an_app_online_whose_address_fails_asks_for_a_fix_with_what_it_answered(): void
    {
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle'));
        $deployment = $this->needingAttention([
            ['path' => '/', 'status' => 500, 'passed' => false],
            ['path' => 'up', 'status' => null, 'passed' => false],
            ['path' => 'about', 'status' => 200, 'passed' => true],
        ]);

        $response = $this->actingAs($this->project->owner)->post(route('check-fixes.store', $this->project));

        $fix = FeatureRequest::sole();
        $response->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->uuid]));
        $this->assertSame('Fix what stops my app working online.', $fix->prompt);
        $this->assertSame(['deployment_id' => $deployment->id, 'checks' => [
            ['name' => 'Opening /', 'output' => 'It answered with status 500.'],
            ['name' => 'Opening /up', 'output' => 'It did not answer within 10 seconds.'],
        ], 'online' => true], $fix->failed_checks);

        $instructions = $fix->instructions();
        $this->assertStringContainsString('these checks of its live address failed', $instructions);
        $this->assertStringContainsString('- Opening /: It answered with status 500.', $instructions);
        $this->assertStringNotContainsString('about', $instructions);
        $this->assertStringNotContainsString('so it did not go online', $instructions);

        // A second click opens the same fix rather than paying for it twice.
        $this->actingAs($this->project->owner)
            ->post(route('check-fixes.store', $this->project))
            ->assertRedirect(route('projects.show', ['project' => $this->project, 'change' => $fix->uuid]));
        $this->assertSame(1, FeatureRequest::count());
    }

    public function test_an_app_online_where_people_cannot_sign_in_asks_for_a_fix_of_signing_in(): void
    {
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle'));
        $this->needingAttention([
            ['path' => '/', 'status' => 200, 'passed' => true],
            ['path' => 'login', 'status' => 500, 'passed' => false, 'key' => 'auth.sign-in'],
        ]);

        $this->actingAs($this->project->owner)->post(route('check-fixes.store', $this->project))->assertSessionHasNoErrors();

        $this->assertSame([[
            'name' => 'Signing in at /login',
            'output' => 'A sign-in attempt with an account that does not exist got status 500, not a refusal.',
        ]], FeatureRequest::sole()->failed_checks['checks'] ?? null);
    }

    public function test_a_host_still_starting_the_new_version_has_nothing_to_fix(): void
    {
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->never());
        $this->needingAttention([], 'Your hosting is taking longer than usual to start the new version.');

        $this->actingAs($this->project->owner)
            ->post(route('check-fixes.store', $this->project))
            ->assertSessionHasErrors(['fix' => 'No check stopped your app going online.']);

        $this->assertSame(0, FeatureRequest::count());
    }

    public function test_a_fix_asked_while_our_ai_account_is_empty_stops_with_our_fault_and_a_retry(): void
    {
        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();
        config([
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);
        FeaturePlanner::fake(fn () => throw InsufficientCreditsException::forProvider('anthropic'));
        $this->project->update(['source_path' => $this->makeProjectSource()]);
        $deployment = $this->needingAttention([['path' => '/', 'status' => 500, 'passed' => false]]);

        $this->actingAs($this->project->owner)->post(route('check-fixes.store', $this->project));

        $fix = FeatureRequest::sole();
        $this->assertSame(StopReason::OutOfCredit, $fix->latestRun?->stop_reason);
        $this->actingAs($this->project->owner)
            ->get(route('feature-requests.show', $fix))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_retry', true)
                ->where('run.error', 'This is our fault: our account with the AI service we use is out of credit. We have been told. Nothing in your app changed. Try again later.')
                ->etc());
        // What is online is left as it was, so the owner can still go back or fix it later.
        $this->assertSame(DeploymentStatus::NeedsAttention, $deployment->refresh()->status);
    }

    /**
     * Make the project's latest publish one that went online but failed
     * the checks of its address.
     *
     * @param  list<array{path: string, status: int|null, passed: bool, key?: string}>  $health
     */
    protected function needingAttention(array $health, string $error = 'Your hosting has the new version, but the app is not answering properly at https://shop.example.com.'): Deployment
    {
        return Deployment::factory()->for($this->project)->create([
            'user_id' => $this->project->user_id,
            'status' => DeploymentStatus::NeedsAttention,
            'checks' => [['name' => 'Tests', 'passed' => true]],
            'health' => $health,
            'error' => $error,
        ]);
    }
}
