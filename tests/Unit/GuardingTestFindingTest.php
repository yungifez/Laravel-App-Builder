<?php

namespace Tests\Unit;

use App\Runs\Review;
use Tests\TestCase;

class GuardingTestFindingTest extends TestCase
{
    protected const GUARD = 'test_guest_is_sent_to_login_from_dashboard';

    /**
     * A review whose reviewer blocked on the given findings.
     *
     * @param  list<array{string, string|null}>  $findings  Summary and file of each blocking finding
     */
    protected function review(array $findings): Review
    {
        return Review::fromModelOutput([
            'approved' => false,
            'summary' => 'The booking page is built.',
            'findings' => array_map(fn (array $finding) => ['severity' => 'blocking', 'summary' => $finding[0], 'file' => $finding[1]], $findings),
        ]);
    }

    /**
     * The new tests as the verification measured them.
     *
     * @return list<array{file: string, name: string, without_change: string}>
     */
    protected function measured(string $other = 'failed'): array
    {
        return [
            ['file' => 'tests/Feature/BookingTest.php', 'name' => self::GUARD, 'without_change' => 'passed'],
            ['file' => 'tests/Feature/BookingTest.php', 'name' => 'test_customer_books_a_free_time', 'without_change' => $other],
        ];
    }

    public function test_a_test_that_guards_what_the_app_already_did_is_a_minor_finding()
    {
        $review = $this->review([[self::GUARD.' passes without the change, so it does not check the new behaviour.', 'tests/Feature/BookingTest.php']])
            ->withGuardingTestsMinor($this->measured());

        $this->assertSame('minor', $review->findings[0]['severity']);
        $this->assertTrue($review->approved);
    }

    public function test_only_the_finding_about_the_guarding_test_turns_minor()
    {
        $review = $this->review([
            [self::GUARD.' passes without the change.', null],
            ['The owner account is seeded with the password "password".', 'config/booking.php'],
        ])->withGuardingTestsMinor($this->measured());

        $this->assertSame(['minor', 'blocking'], array_column($review->findings, 'severity'));
        $this->assertFalse($review->approved);
    }

    public function test_it_stays_blocking_when_no_new_test_fails_without_the_change_or_the_finding_is_about_app_code()
    {
        // Nothing shows the change works, so the finding is a real gap.
        $allPass = $this->review([[self::GUARD.' passes without the change.', 'tests/Feature/BookingTest.php']])
            ->withGuardingTestsMinor($this->measured(other: 'passed'));

        $this->assertSame('blocking', $allPass->findings[0]['severity']);
        $this->assertFalse($allPass->approved);

        // Naming the test does not excuse a problem in the app's own code.
        $appCode = $this->review([['The dashboard lost its policy check; '.self::GUARD.' cannot notice.', 'app/Http/Controllers/DashboardController.php']])
            ->withGuardingTestsMinor($this->measured());

        $this->assertSame('blocking', $appCode->findings[0]['severity']);
        $this->assertSame($appCode, $appCode->withGuardingTestsMinor([]));
    }
}
