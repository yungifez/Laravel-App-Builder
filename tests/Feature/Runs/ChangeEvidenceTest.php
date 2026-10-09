<?php

namespace Tests\Feature\Runs;

use App\Context\ChangeClassification;
use Tests\TestCase;

class ChangeEvidenceTest extends TestCase
{
    /**
     * A change about bookings that also touched billing, where only the
     * bookings tests ran the changed code.
     */
    private function classification(array $unclaimed = []): ChangeClassification
    {
        return ChangeClassification::fromArray([
            'requested' => ['bookings' => ['app/Bookings/Book.php']],
            'may_also_affect' => ['billing' => ['app/Billing/Charge.php']],
            'unexpected' => [],
            'unclaimed' => $unclaimed,
            'context_updates' => [],
            'notes_behind' => [],
            'targets' => ['bookings', 'members'],
            'observed' => ['areas' => ['bookings' => 3], 'tests' => 3, 'unmapped' => [], 'foundation' => [], 'by_line' => []],
        ]);
    }

    public function test_a_behaviour_change_in_a_changed_area_its_tests_ran_is_tested()
    {
        $this->assertSame('tested', $this->classification()->evidenceFor('bookings'));
    }

    public function test_a_behaviour_change_only_the_changed_files_show_is_in_the_change()
    {
        $this->assertSame('in_change', $this->classification()->evidenceFor('billing'));
        // A line with no area is backed by changed files no area claims.
        $this->assertSame('in_change', $this->classification(unclaimed: ['app/Other.php'])->evidenceFor(null));
    }

    public function test_a_behaviour_change_in_an_area_the_change_never_touched_is_not_in_the_change()
    {
        // Members is one of the change's areas, but none of its files changed.
        $this->assertSame('not_in_change', $this->classification()->evidenceFor('members'));
        $this->assertSame('not_in_change', $this->classification()->evidenceFor('settings'));
        $this->assertSame('not_in_change', $this->classification()->evidenceFor(null));
    }
}
