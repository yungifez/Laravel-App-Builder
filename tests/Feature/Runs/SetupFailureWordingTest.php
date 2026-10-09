<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\TransitionRun;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\SetupFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SetupFailureWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_reads_the_stage_and_operators_keep_what_the_step_printed()
    {
        $error = SetupFailure::step('Generate route helpers', timedOut: false)."\nThe setup step \"Generate route helpers\" failed. Class \"Wayfinder\" not found";
        $request = FeatureRequest::factory()->create(['status' => FeatureRequestStatus::Failed, 'error' => $error]);
        $run = Run::factory()->implementing()->for($request)->create();

        app(TransitionRun::class)->handle($run, RunStatus::Failed, attributes: ['error' => $error], details: ['reason' => StopReason::ConstructionFailed]);

        $said = "Something in your app's code went wrong while building your app's pages, before I changed anything. Nothing in your app changed. Ask one of our developers to look at it.";

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.error', $said)
                ->where('run.error', $said));

        $this->assertSame(
            "Something in your app's code went wrong while building your app's pages, before I changed anything. Ask one of our developers to look at it.",
            $request->user->notifications()->sole()->data['reason'],
        );
        $this->assertStringContainsString('Class "Wayfinder" not found', (string) $run->refresh()->error);
    }

    public function test_the_same_setup_stop_twice_keeps_its_advice_and_says_nothing_new()
    {
        $error = SetupFailure::step('Install Node dependencies', timedOut: false);
        $first = FeatureRequest::factory()->create(['status' => FeatureRequestStatus::Failed]);
        Run::factory()->for($first)->create(['status' => RunStatus::Failed, 'stop_reason' => 'construction_failed', 'error' => $error."\nnpm error one"]);
        $again = FeatureRequest::factory()->for($first->project)->for($first->user)->create(['status' => FeatureRequestStatus::Failed, 'retry_of_id' => $first->id]);
        Run::factory()->for($again)->create(['status' => RunStatus::Failed, 'stop_reason' => 'construction_failed', 'error' => $error."\nnpm error two"]);

        // The output differs from try to try; the stage is what repeats.
        $this->actingAs($first->user)
            ->get(route('feature-requests.show', $again))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.failed_same_way', true)
                ->where('run.error', 'This is our fault: something went wrong on our side while getting the parts your app is built from. Nothing in your app changed. It stopped the same way last time, so trying again will likely stop the same way. Ask for a smaller part of it in the chat, or ask one of our developers.'));
    }
}
