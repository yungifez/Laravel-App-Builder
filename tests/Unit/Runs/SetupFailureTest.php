<?php

namespace Tests\Unit\Runs;

use App\Runs\SetupFailure;
use Tests\TestCase;

class SetupFailureTest extends TestCase
{
    public function test_a_step_of_the_apps_own_code_says_the_app_is_at_fault_and_who_can_help()
    {
        $this->assertSame(
            "Something in your app's code went wrong while setting up your app's data, before I changed anything. Nothing in your app changed. Ask one of our developers to look at it.",
            SetupFailure::step('Create the database', timedOut: false),
        );
    }

    public function test_a_step_of_ours_says_it_is_our_fault_and_to_try_again()
    {
        $this->assertSame(
            'This is our fault: something went wrong on our side while getting the parts your app is built from. Nothing in your app changed. Try again.',
            SetupFailure::step('Install Node dependencies', timedOut: false),
        );

        // A step this list does not know is still ours.
        $this->assertStringStartsWith('This is our fault: something went wrong on our side while getting your app ready.', SetupFailure::step('Warm the cache', timedOut: false));
    }

    public function test_a_step_that_ran_out_of_time_is_ours_whatever_it_was()
    {
        $this->assertSame(
            "This is our fault: building your app's pages took too long, so I stopped before changing anything. Nothing in your app changed. Try again.",
            SetupFailure::step('Build the screens', timedOut: true),
        );
    }
}
