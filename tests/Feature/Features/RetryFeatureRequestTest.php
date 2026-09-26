<?php

namespace Tests\Feature\Features;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RetryFeatureRequestTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ExecuteRun::class]);
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner, 'owner')->create();
    }

    public function test_a_change_that_ran_out_of_budget_can_be_tried_again()
    {
        $stopped = $this->stopped(RunStatus::NeedsUserDecision, ['selection' => ['file' => 'resources/js/pages/Welcome.vue', 'line' => 3, 'column' => 5, 'tag' => 'h1', 'text' => 'Hi', 'area' => null]]);

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $stopped))
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.can_retry', true));

        $response = $this->post(route('feature-requests.retries.store', $stopped));

        $retry = $this->project->featureRequests()->latest('id')->first();
        $this->assertNotNull($retry);
        $this->assertFalse($retry->is($stopped));
        $response->assertRedirect(route('feature-requests.show', $retry));
        $this->assertSame($stopped->prompt, $retry->prompt);
        $this->assertSame($stopped->selection, $retry->selection);
        $this->assertSame(FeatureRequestStatus::Generating, $retry->status);
        $this->assertSame(RunStatus::NeedsUserDecision, $stopped->latestRun?->status);
        Queue::assertPushed(ExecuteRun::class);
    }

    public function test_a_failed_change_can_be_tried_again()
    {
        $stopped = $this->stopped(RunStatus::Failed);

        $this->actingAs($this->owner)->post(route('feature-requests.retries.store', $stopped))->assertSessionHasNoErrors();

        $this->assertSame(2, $this->project->featureRequests()->count());
    }

    public function test_a_change_that_did_not_stop_is_not_tried_again()
    {
        $running = FeatureRequest::factory()->for($this->project)->create();
        Run::factory()->for($running)->implementing()->create();
        $generated = FeatureRequest::factory()->for($this->project)->generated()->create();

        $this->actingAs($this->owner);

        foreach ([$running, $generated] as $featureRequest) {
            $this->get(route('feature-requests.show', $featureRequest))
                ->assertInertia(fn (Assert $page) => $page->where('featureRequest.can_retry', false));

            $this->post(route('feature-requests.retries.store', $featureRequest))
                ->assertSessionHasErrors(['retry' => 'This change did not stop, so there is nothing to try again.']);
        }

        $this->assertSame(2, $this->project->featureRequests()->count());
    }

    public function test_other_people_cannot_try_someone_elses_change_again()
    {
        $stopped = $this->stopped(RunStatus::Failed);

        $this->actingAs(User::factory()->create())
            ->post(route('feature-requests.retries.store', $stopped))
            ->assertForbidden();

        $this->assertSame(1, $this->project->featureRequests()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function stopped(RunStatus $status, array $attributes = []): FeatureRequest
    {
        $featureRequest = FeatureRequest::factory()->for($this->project)->create(['status' => FeatureRequestStatus::Failed] + $attributes);
        Run::factory()->for($featureRequest)->create(['status' => $status, 'error' => 'The agent used up its turns or budget before finishing.']);

        return $featureRequest;
    }
}
