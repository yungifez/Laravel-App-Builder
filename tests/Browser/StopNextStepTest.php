<?php

use App\Enums\FeatureRequestStatus;
use App\Enums\NextStep;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;

/*
| Every stop the owner can meet, in the change's chat: it says why, in the
| words the stop has for the owner, and offers exactly the one next step
| the stop names. The stops come from StopReason itself, so a new one is
| held to this as soon as it exists.
*/

beforeEach(function () {
    config(['operations.operators' => [], 'billing.plans.free.monthly_usd' => 5, 'builder.construction.budgets.daily_usd' => 10]);

    $this->owner = User::factory()->create(['detail_level' => 1]);
    $this->project = Project::factory()->create(['user_id' => $this->owner->id]);
    $this->actingAs($this->owner);
});

/**
 * Make a change whose run stopped for the reason, the way the run leaves
 * it: failed, waiting on the owner, or cancelled.
 */
function stoppedFor(Project $project, StopReason $reason): FeatureRequest
{
    $status = match ($reason) {
        StopReason::ConstructionFailed, StopReason::CannotGenerate, StopReason::SpendLimit, StopReason::UsageLimit, StopReason::WorkerLapsed, StopReason::WorkerStopped => RunStatus::Failed,
        StopReason::Cancelled => RunStatus::Cancelled,
        StopReason::BudgetExhausted, StopReason::ProvidersUnavailable, StopReason::OutOfCredit, StopReason::RequestRefused, StopReason::Question, StopReason::FindingProposed,
        StopReason::WrittenTestsChanged, StopReason::NoChanges, StopReason::ReviewFindings, StopReason::VerificationFailed, StopReason::VerificationInterrupted, StopReason::WrittenTestStillFails => RunStatus::NeedsUserDecision,
    };

    $change = FeatureRequest::factory()->for($project)->for($project->owner, 'user')->create([
        'prompt' => "Stop for {$reason->value}",
        'status' => match (true) {
            $reason === StopReason::Cancelled => FeatureRequestStatus::Cancelled,
            $reason === StopReason::FindingProposed => FeatureRequestStatus::Generated,
            $reason->nextStep() === NextStep::Answer => FeatureRequestStatus::Generating,
            default => FeatureRequestStatus::Failed,
        },
    ]);
    $run = Run::factory()->for($change)->create([
        'status' => $status,
        'stop_reason' => $reason,
        // What operators read; the owner reads the stop's own words.
        'error' => $reason === StopReason::Cancelled ? null : "Operator detail for {$reason->value}",
        'question' => $reason === StopReason::Question ? ['text' => 'Who may tick items off?', 'why' => '', 'options' => ['Anyone', 'Only who added it'], 'recommended' => null] : null,
    ]);
    $run->recordEvent('status', ['from' => 'implementing', 'to' => $status->value, 'reason' => $reason->value]);

    return $change->fresh();
}

/**
 * What happened and whose fault it is: the stop's first sentence. A
 * limit's stop is said again with when it lifts, so for those only whose
 * fault it is stays fixed.
 */
function stopSays(StopReason $reason): string
{
    return match ($reason) {
        StopReason::SpendLimit => __('This is our fault'),
        StopReason::UsageLimit => __('You have used all the AI use'),
        default => Str::before($reason->said(), '. ').'.',
    };
}

function chatUrl(FeatureRequest $change): string
{
    return route('projects.show', ['project' => $change->project, 'change' => $change->uuid], absolute: false);
}

it('shows every stop with its own words and exactly its one next step', function () {
    // The month's plan is used up, today's spending limit is not.
    stoppedFor($this->project, StopReason::ConstructionFailed)->latestRun->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 6]);

    $page = null;
    // Every stop is looked at, and each one shown wrongly is named.
    $wrong = [];

    foreach (StopReason::cases() as $reason) {
        $change = stoppedFor($this->project, $reason);
        $page = $page === null ? visit(chatUrl($change)) : $page->navigate(chatUrl($change));

        try {
            $page->assertSee("Stop for {$reason->value}")
                ->assertDontSee("Operator detail for {$reason->value}");

            match ($reason->nextStep()) {
                NextStep::Retry => $page
                    ->assertSee($reason === StopReason::Cancelled ? 'You stopped this' : stopSays($reason))
                    ->assertVisible('[data-test="retry-button"]')
                    ->assertMissing('[data-test="thread-see-plan"]')
                    ->assertMissing('[data-test="thread-asks"]'),
                NextStep::Settings => $page
                    ->assertSee(stopSays($reason))
                    ->assertVisible('[data-test="thread-see-plan"]')
                    ->assertMissing('[data-test="retry-button"]'),
                NextStep::Answer => $page
                    ->assertSee($reason === StopReason::Question ? 'Who may tick items off?' : stopSays($reason))
                    ->assertMissing('[data-test="thread-failed"]')
                    ->assertMissing('[data-test="retry-button"]'),
                NextStep::Contact => $page
                    ->assertVisible('[data-test="ask-developer-after-failure"]')
                    ->assertMissing('[data-test="retry-button"]'),
            };
        } catch (AssertionFailedError $failure) {
            $wrong[$reason->value] = Str::before($failure->getMessage(), ' on the page');
        }
    }

    expect($wrong)->toBe([]);
    $page->assertNoJavaScriptErrors();
});

it('offers to try again once the limit that stopped a change has lifted', function () {
    // Nothing is spent this month, so the plan has use left again.
    $change = stoppedFor($this->project, StopReason::UsageLimit);

    visit(chatUrl($change))
        ->assertVisible('[data-test="retry-button"]')
        ->assertMissing('[data-test="thread-see-plan"]')
        ->assertNoJavaScriptErrors();
});

it('withholds trying again where it would only stop the same way', function () {
    // Today's spending limit is reached.
    stoppedFor($this->project, StopReason::ConstructionFailed)->latestRun->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 11]);
    $spent = stoppedFor($this->project, StopReason::SpendLimit);

    // Tried again already: that try stands for it now.
    $retried = stoppedFor($this->project, StopReason::ConstructionFailed);
    FeatureRequest::factory()->for($this->project)->for($this->owner, 'user')->create(['retry_of_id' => $retried->id, 'prompt' => 'The newer try']);

    visit(chatUrl($spent))
        ->assertSee(stopSays(StopReason::SpendLimit))
        ->assertMissing('[data-test="retry-button"]')
        ->navigate(chatUrl($retried))
        ->assertSee(stopSays(StopReason::ConstructionFailed))
        ->assertMissing('[data-test="retry-button"]')
        // It points on to that try instead.
        ->click('[data-test="tried-again"]')
        ->assertSee('The newer try')
        ->assertMissing('[data-test="tried-again"]')
        ->assertNoJavaScriptErrors();
});
