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
                ->where('featureRequest.error', 'This is our fault: when I looked over the change, I found problems I could not fix, so I stopped. Nothing in your app changed. Try again, or ask in other words.')
                ->where('run.error', 'This is our fault: when I looked over the change, I found problems I could not fix, so I stopped. Nothing in your app changed. Try again, or ask in other words.'));

        $this->assertSame('The review found problems this run cannot fix: Still missing authorization.', $run->refresh()->error);
    }

    public function test_a_stop_after_failing_checks_says_so()
    {
        $request = FeatureRequest::factory()->create();
        Run::factory()->for($request)->create([
            'status' => RunStatus::NeedsUserDecision,
            'error' => 'Verification did not pass, and this run cannot repair the change.',
        ]);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.error', "This is our fault: your app's checks still failed after I tried to fix them, so I stopped. Nothing in your app changed. Try again, or ask in other words."));
    }

    public function test_a_stop_from_an_empty_ai_account_does_not_say_try_in_a_few_minutes()
    {
        $request = FeatureRequest::factory()->create();
        Run::factory()->for($request)->create([
            'status' => RunStatus::NeedsUserDecision,
            'error' => "No AI provider could take the task right now (Quota exceeded. Check your plan and billing details.\n). Try again later.",
        ]);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.error', 'This is our fault: our account with the AI service we use cannot take more work right now. Nothing in your app changed. Try again later.'));
    }

    public function test_a_stop_that_found_nothing_to_change_says_what_was_checked()
    {
        $request = FeatureRequest::factory()->create();
        $run = Run::factory()->for($request)->create([
            'status' => RunStatus::NeedsUserDecision,
            'error' => 'The run finished without changing the project.',
        ]);
        $run->recordEvent('build_finished', ['attempt' => 0, 'account' => "I opened the front page and its links.\n\nNothing was broken, so I changed nothing."]);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.found_nothing', "I opened the front page and its links.\n\nNothing was broken, so I changed nothing."));

        // Any other stop has no such account.
        $run->update(['error' => 'Verification did not pass, and this run cannot repair the change.']);

        $this->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('run.found_nothing', null));
    }

    public function test_an_unknown_failure_says_it_is_our_fault_without_the_detail()
    {
        $request = FeatureRequest::factory()->create();
        Run::factory()->for($request)->create([
            'status' => RunStatus::Failed,
            'error' => 'The run stopped unexpectedly.',
        ]);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.error', 'This is our fault: something went wrong on our side while I worked on this. Nothing in your app changed. Try again.'));
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
