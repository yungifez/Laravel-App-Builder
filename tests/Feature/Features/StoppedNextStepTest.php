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

class StoppedNextStepTest extends TestCase
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

    public function test_a_stopped_change_tried_again_leads_to_the_newer_try(): void
    {
        $stopped = $this->stopped();

        $this->actingAs($this->owner)->post(route('feature-requests.retries.store', $stopped))->assertSessionHasNoErrors();
        $retry = FeatureRequest::query()->where('retry_of_id', $stopped->id)->sole();

        $this->assertNextStep($stopped, canRetry: false, stopped: true, triedAgain: $retry->uuid);
    }

    public function test_a_stopped_change_not_tried_again_offers_to_try_it(): void
    {
        $stopped = $this->stopped();

        $this->actingAs($this->owner);

        $this->assertNextStep($stopped, canRetry: true, stopped: true, triedAgain: null);
    }

    public function test_a_change_that_did_not_stop_offers_no_next_step(): void
    {
        $running = FeatureRequest::factory()->for($this->project)->create();
        Run::factory()->for($running)->implementing()->create();
        $made = FeatureRequest::factory()->for($this->project)->generated()->create();
        $asking = $this->stopped(['question' => 'Which page?']);

        $this->actingAs($this->owner);

        foreach ([$running, $made, $asking] as $featureRequest) {
            $this->assertNextStep($featureRequest, canRetry: false, stopped: false, triedAgain: null);
        }
    }

    /**
     * Make a change whose run stopped before finishing.
     *
     * @param  array<string, mixed>  $run
     */
    protected function stopped(array $run = []): FeatureRequest
    {
        $featureRequest = FeatureRequest::factory()->for($this->project)->create(['status' => FeatureRequestStatus::Failed]);
        Run::factory()->for($featureRequest)->create(['status' => RunStatus::Failed, 'error' => 'The agent used up its turns or budget before finishing.'] + $run);

        return $featureRequest;
    }

    protected function assertNextStep(FeatureRequest $featureRequest, bool $canRetry, bool $stopped, ?string $triedAgain): void
    {
        $this->get(route('feature-requests.show', $featureRequest))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_retry', $canRetry)
                ->where('featureRequest.stopped', $stopped)
                ->where('featureRequest.tried_again', $triedAgain)
                ->etc());
    }
}
