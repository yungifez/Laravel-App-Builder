<?php

namespace Tests\Feature\Features;

use App\Actions\Runs\TransitionRun;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Features\SpendPause;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RepeatedFailureTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner, 'owner')->create();
    }

    /**
     * Make a change that stopped, optionally as a try of an earlier one.
     */
    protected function stopped(string $stopReason, string $error, ?FeatureRequest $tries = null): FeatureRequest
    {
        $featureRequest = FeatureRequest::factory()->for($this->project)->for($this->owner)->create([
            'status' => FeatureRequestStatus::Failed,
            'retry_of_id' => $tries?->id,
        ]);
        Run::factory()->for($featureRequest)->create(['status' => RunStatus::Failed, 'stop_reason' => $stopReason, 'error' => $error]);

        return $featureRequest;
    }

    public function test_a_try_that_stops_just_as_the_last_one_says_trying_again_will_not_help()
    {
        $first = $this->stopped('construction_failed', "The setup step \"Build the screens\" failed.\nvite: error one");
        $again = $this->stopped('construction_failed', "The setup step \"Build the screens\" failed.\nvite: error two", $first);

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $again))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.failed_same_way', true)
                ->where('featureRequest.can_retry', true)
                ->where('run.error', 'This is our fault: something went wrong on our side while I worked on this. Nothing in your app changed. It stopped the same way last time, so trying again will likely stop the same way. Ask for a smaller part of it in the chat, or ask one of our developers.'));

        // The first try had nothing to repeat.
        $this->get(route('feature-requests.show', $first))
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.failed_same_way', false));
    }

    public function test_a_try_that_stops_another_way_still_offers_to_try_again()
    {
        $first = $this->stopped('construction_failed', 'The setup step "Install Node dependencies" failed.');
        $again = $this->stopped('construction_failed', 'The setup step "Generate route helpers" failed.', $first);

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $again))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.failed_same_way', false)
                ->where('run.error', 'This is our fault: something went wrong on our side while I worked on this. Nothing in your app changed. Try again.'));
    }

    public function test_a_stop_that_says_when_to_try_again_keeps_its_own_advice()
    {
        $paused = 'This is our fault: we paused new work for today to keep our costs in check. Nothing in your app changed. Try again tomorrow.';
        $first = $this->stopped('spend_limit', $paused);
        $again = $this->stopped('spend_limit', $paused, $first);
        // Today's spend is still at the limit, so the pause holds.
        config(['builder.construction.budgets.daily_usd' => 10]);
        Run::factory()->create()->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 10.5]);

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $again))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.failed_same_way', false)
                ->where('featureRequest.can_retry', false)
                ->where('run.error', SpendPause::message()));
    }

    public function test_a_pause_that_is_over_no_longer_tells_the_owner_to_wait()
    {
        $paused = 'This is our fault: we paused new work for today to keep our costs in check. Nothing in your app changed. Try again tomorrow.';
        $stopped = $this->stopped('spend_limit', $paused);

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $stopped))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_retry', true)
                ->where('run.error', 'This is our fault: we paused new work for a day to keep our costs in check. That pause is over, so you can try again now. Nothing in your app changed.'));
    }

    public function test_the_notice_of_a_repeated_stop_says_so()
    {
        $error = 'This is our fault: the AI stopped before it finished the change. Nothing in your app changed. Try again.';
        $first = $this->stopped('construction_failed', $error);
        $again = FeatureRequest::factory()->for($this->project)->for($this->owner)->create(['retry_of_id' => $first->id]);
        $run = Run::factory()->implementing()->for($again)->create();

        app(TransitionRun::class)->handle($run, RunStatus::Failed, attributes: ['error' => $error], details: ['reason' => StopReason::ConstructionFailed]);

        $this->assertSame(
            'This is our fault: the AI stopped before it finished the change. It stopped the same way last time, so trying again will likely stop the same way. Ask for a smaller part of it in the chat, or ask one of our developers.',
            $this->owner->notifications()->sole()->data['reason'],
        );
    }
}
