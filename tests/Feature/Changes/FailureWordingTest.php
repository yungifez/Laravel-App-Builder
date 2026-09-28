<?php

namespace Tests\Feature\Changes;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FailureWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_change_says_it_is_our_fault_and_keeps_the_detail_for_operators()
    {
        $request = FeatureRequest::factory()->create([
            'status' => FeatureRequestStatus::Failed,
            'error' => 'The review found problems this run cannot fix: Still missing authorization.',
        ]);
        $run = Run::factory()->for($request)->create([
            'status' => RunStatus::Failed,
            'error' => 'The review found problems this run cannot fix: Still missing authorization.',
        ]);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.error', 'This is our fault: something went wrong on our side while I worked on this. Nothing in your app changed. Try again.')
                ->where('run.error', 'This is our fault: something went wrong on our side while I worked on this. Nothing in your app changed. Try again.'));

        $this->assertSame('The review found problems this run cannot fix: Still missing authorization.', $run->refresh()->error);
    }

    public function test_a_stop_that_already_says_whose_fault_is_shown_as_it_is()
    {
        $request = FeatureRequest::factory()->create();
        Run::factory()->for($request)->create([
            'status' => RunStatus::NeedsUserDecision,
            'error' => 'This is our fault: the AI service we use is busy right now. Nothing in your app changed. Try again in a few minutes.',
        ]);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.error', 'This is our fault: the AI service we use is busy right now. Nothing in your app changed. Try again in a few minutes.'));
    }

    public function test_a_change_the_owner_cancelled_is_not_called_our_fault()
    {
        $request = FeatureRequest::factory()->create([
            'status' => FeatureRequestStatus::Cancelled,
            'error' => 'The run was cancelled.',
        ]);
        Run::factory()->for($request)->create([
            'status' => RunStatus::Cancelled,
            'error' => 'The run was cancelled.',
        ]);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.error', 'The run was cancelled.')
                ->where('run.error', 'The run was cancelled.'));
    }
}
