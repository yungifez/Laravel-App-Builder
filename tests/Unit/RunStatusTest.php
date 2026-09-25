<?php

namespace Tests\Unit;

use App\Enums\RunStatus;
use PHPUnit\Framework\TestCase;

class RunStatusTest extends TestCase
{
    public function test_the_happy_path_moves_forward_one_state_at_a_time()
    {
        $path = [RunStatus::Queued, RunStatus::Planning, RunStatus::Implementing, RunStatus::Verifying, RunStatus::Reviewing, RunStatus::Completed];

        foreach (array_slice($path, 1) as $index => $next) {
            $this->assertTrue($path[$index]->canTransitionTo($next), "{$path[$index]->value} → {$next->value}");
        }

        $this->assertFalse(RunStatus::Queued->canTransitionTo(RunStatus::Implementing));
        $this->assertFalse(RunStatus::Implementing->canTransitionTo(RunStatus::Completed));
    }

    public function test_repairs_return_to_implementing()
    {
        $this->assertTrue(RunStatus::Verifying->canTransitionTo(RunStatus::Implementing));
        $this->assertTrue(RunStatus::Reviewing->canTransitionTo(RunStatus::Implementing));
        $this->assertTrue(RunStatus::NeedsUserDecision->canTransitionTo(RunStatus::Implementing));
    }

    public function test_cancelling_only_leads_to_cancelled_and_finished_runs_never_change()
    {
        $this->assertSame([RunStatus::Cancelled], RunStatus::Cancelling->allowedTransitions());

        foreach ([RunStatus::Completed, RunStatus::Cancelled, RunStatus::Failed] as $finished) {
            $this->assertTrue($finished->finished());
            $this->assertSame([], $finished->allowedTransitions());
        }

        foreach (RunStatus::cases() as $status) {
            if (! $status->finished() && $status !== RunStatus::Cancelling) {
                $this->assertTrue($status->canTransitionTo(RunStatus::Cancelling), $status->value);
            }
        }
    }
}
