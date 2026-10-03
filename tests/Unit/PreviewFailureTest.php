<?php

namespace Tests\Unit;

use App\Previews\PreviewFailure;
use Tests\TestCase;

class PreviewFailureTest extends TestCase
{
    public function test_a_step_that_ran_out_of_time_says_how_long_it_had_and_that_it_is_our_fault()
    {
        $this->assertSame(
            'Setting up your app\'s data took more than 10 minutes, so I stopped. This is our fault. Try once more.',
            PreviewFailure::step('Create the database', timedOut: true, timeoutSeconds: 600),
        );
        $this->assertStringContainsString('more than a minute,', PreviewFailure::step('Generate app key', timedOut: true, timeoutSeconds: 60));
    }

    public function test_a_step_that_runs_the_apps_own_code_points_the_owner_to_the_chat()
    {
        $this->assertSame(
            'Something in your app\'s code went wrong while building your app\'s pages. Ask me in the chat to fix it.',
            PreviewFailure::step('Build the frontend', timedOut: false, timeoutSeconds: 600),
        );
    }

    public function test_any_other_step_is_our_fault()
    {
        $this->assertSame(
            'Something went wrong on our side while getting the parts your app is built from. This is our fault. Try once more.',
            PreviewFailure::step('Install PHP dependencies', timedOut: false, timeoutSeconds: 900),
        );
        $this->assertStringContainsString('while getting your app ready. This is our fault.', PreviewFailure::step('Something new', timedOut: false, timeoutSeconds: 60));
    }

    public function test_an_app_that_never_answered_is_our_fault()
    {
        $this->assertSame('Your app did not answer within 30 seconds of starting. This is our fault. Try once more.', PreviewFailure::notUp(null, 30));
        $this->assertStringContainsString('did not answer', PreviewFailure::notUp(404, 30));
        $this->assertStringContainsString('shows an error', PreviewFailure::notUp(503, 30));
    }
}
