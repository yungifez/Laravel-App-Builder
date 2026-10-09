<?php

namespace Tests\Feature\Runs;

use App\Enums\FeatureRequestStatus;
use App\Enums\NextStep;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Features\OwnerWording;
use App\Features\RepeatedFailure;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Throwable;

/**
 * Every way a change can stop ends in a clear next step: the owner is told
 * what happened, whose fault it is and what to do, and a stop that repeats
 * gets other advice or is never called a repeat on purpose.
 */
class StopReasonTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_stop_says_whose_fault_it_is_and_what_to_do_next()
    {
        $problems = [];

        foreach (StopReason::cases() as $stop) {
            try {
                $problems = [...$problems, ...$this->problems($stop->value, $stop->said(), $stop->ours(), $stop->nextStep())];
            } catch (Throwable $exception) {
                $problems[] = "{$stop->value}: {$exception->getMessage()}";
            }

            // The page says the same as the stop when the run kept no words
            // for the owner.
            $this->assertSame($stop->said(), OwnerWording::failure('Operator detail.', $stop), $stop->value);
        }

        $this->assertSame([], $problems);

        // The advice holds no stop the code cannot record.
        $this->assertSame([], array_values(array_filter(
            array_keys([...RepeatedFailure::ADVICE, ...RepeatedFailure::EXCLUDED]),
            fn (string $name) => StopReason::tryFrom($name) === null,
        )));
    }

    public function test_a_stop_that_is_ours_says_so_and_one_that_is_not_never_does()
    {
        $this->assertStringStartsWith('This is our fault', StopReason::VerificationInterrupted->said());
        $this->assertStringNotContainsString('our fault', StopReason::UsageLimit->said());
        $this->assertSame(NextStep::Settings, StopReason::UsageLimit->nextStep());
        $this->assertStringNotContainsString('our fault', StopReason::Question->said());
        $this->assertSame(NextStep::Answer, StopReason::Question->nextStep());

        // Calling a fault ours where it is not, or the other way round, is
        // caught by name.
        $this->assertSame(['usage_limit: is ours but does not say "This is our fault"'], $this->problems('usage_limit', StopReason::UsageLimit->said(), true, NextStep::Settings));
        $this->assertSame(['verification_interrupted: is not ours but says "our fault"'], $this->problems('verification_interrupted', StopReason::VerificationInterrupted->said(), false, NextStep::Retry));
    }

    public function test_a_new_stop_without_wording_or_advice_fails_with_its_name()
    {
        $this->assertSame([
            'disk_full: has no words for the owner',
            'disk_full: is ours but does not say "This is our fault"',
            'disk_full: does not say its next step "Try again"',
            'disk_full: is neither advised on when it repeats nor excluded with a reason',
        ], $this->problems('disk_full', '', true, NextStep::Retry));

        // A stop the page cannot act on is caught too.
        $this->assertSame(['no_changes: does not say its next step "Settings"'], $this->problems('no_changes', StopReason::NoChanges->said(), true, NextStep::Settings));
    }

    public function test_a_stop_that_asks_offers_only_the_answer()
    {
        $asked = $this->stopped(StopReason::FindingProposed, 'I asked you about something the checks found. Read it in how we know the change works, and answer.');

        $this->actingAs($asked->user)
            ->get(route('feature-requests.show', $asked))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.next_step', 'answer')
                ->where('featureRequest.can_retry', false)
                ->where('featureRequest.stopped', false));
    }

    public function test_a_stop_to_try_again_still_offers_to_try_again()
    {
        $failed = $this->stopped(StopReason::VerificationFailed, 'Verification did not pass, and this run cannot repair the change.');

        $this->actingAs($failed->user)
            ->get(route('feature-requests.show', $failed))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.next_step', 'retry')
                ->where('featureRequest.can_retry', true)
                ->where('featureRequest.stopped', true));
    }

    public function test_a_stop_that_asks_never_offers_to_try_again_even_after_a_repeat()
    {
        $error = 'I asked you about something the checks found. Read it in how we know the change works, and answer.';
        $first = $this->stopped(StopReason::FindingProposed, $error);
        $again = $this->stopped(StopReason::FindingProposed, $error, $first);

        $this->actingAs($again->user)
            ->get(route('feature-requests.show', $again))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.next_step', 'answer')
                ->where('featureRequest.can_retry', false)
                ->where('featureRequest.failed_same_way', false)
                ->where('featureRequest.stopped', false));
    }

    /**
     * Make a change whose run stopped for the owner, optionally as a try of
     * an earlier one.
     */
    protected function stopped(StopReason $stop, string $error, ?FeatureRequest $tries = null): FeatureRequest
    {
        $featureRequest = FeatureRequest::factory()->create([
            'status' => FeatureRequestStatus::Generating,
            'retry_of_id' => $tries?->id,
            ...($tries === null ? [] : ['project_id' => $tries->project_id, 'user_id' => $tries->user_id]),
        ]);
        Run::factory()->for($featureRequest)->create(['status' => RunStatus::NeedsUserDecision, 'stop_reason' => $stop, 'error' => $error]);

        return $featureRequest;
    }

    /**
     * Get what is missing for one stop, each named by the stop.
     *
     * @return list<string>
     */
    protected function problems(string $name, string $said, bool $ours, NextStep $step): array
    {
        $advised = isset(RepeatedFailure::ADVICE[$name]);
        $excluded = trim(RepeatedFailure::EXCLUDED[$name] ?? '') !== '';

        return array_values(array_filter([
            trim($said) === '' ? "{$name}: has no words for the owner" : null,
            $ours && ! str_starts_with($said, 'This is our fault') ? "{$name}: is ours but does not say \"This is our fault\"" : null,
            ! $ours && str_contains($said, 'our fault') ? "{$name}: is not ours but says \"our fault\"" : null,
            ! str_contains($said, $step->words()) ? "{$name}: does not say its next step \"{$step->words()}\"" : null,
            ! $advised && ! $excluded ? "{$name}: is neither advised on when it repeats nor excluded with a reason" : null,
            $advised && $excluded ? "{$name}: is both advised on when it repeats and excluded" : null,
        ]));
    }
}
