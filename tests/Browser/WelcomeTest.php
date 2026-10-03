<?php

use App\Models\User;

/*
| What a visitor learns before signing up: tests, not the AI, decide
| whether a change works, and what they keep. The box they type in, at the
| top or at the end, carries their idea into the new-app form.
*/

it('shows a visitor what makes it different and what they keep, and starts them on an app', function () {
    visit('/')
        ->assertPresent('@welcome-different')
        ->assertPresent('@welcome-features')
        ->assertPresent('@welcome-stage')
        ->assertSeeIn('@welcome-stage', 'Let customers cancel a booking up to a day before.')
        ->assertSee('Build apps that don’t stay prototypes.')
        ->assertSee('Tests decide if it works, not the')
        ->assertSee('Without checks')
        ->assertSee('The checks passed. It is ready for you to keep.')
        ->assertSee('Try it before you keep it')
        ->assertSee('Every change shows what was')
        ->assertSee('Free to start. No card needed.')
        ->assertSee('Checked, with gaps')
        ->assertSee('It stays yours.')
        ->click('@welcome-start')
        ->assertPathIs('/register');
});

it('shows undo as a new step on top of the history', function () {
    visit('/')
        ->click('@welcome-undo')
        ->assertSeeIn('@welcome-yours', 'Undone')
        ->assertSeeIn('@welcome-yours', 'Undo “Let customers cancel a booking up to a day before”');
});

it('keeps what a visitor typed for the new app form after they sign in', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->type('@welcome-idea', 'Customers order cakes for a pickup day.')
        ->click('@welcome-hero-start')
        ->assertPathIs('/projects')
        ->assertValue('#purpose', 'Customers order cakes for a pickup day.');
});

it('starts a visitor from the box at the end of the page', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->type('@welcome-end-idea', 'Neighbours lend each other tools.')
        ->click('@welcome-end-start')
        ->assertPathIs('/projects')
        ->assertValue('#purpose', 'Neighbours lend each other tools.');
});

it('puts the example in an empty box instead of doing nothing', function () {
    visit('/')
        ->click('@welcome-hero-start')
        ->assertPathIs('/')
        ->assertSee('Change it, or press Start again.')
        ->assertValue('@welcome-idea', 'My cleaners see their jobs for the day, and customers book a clean online.');
});

it('takes a signed-in owner to their apps', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->click('@welcome-apps')
        ->assertPathIs('/projects');
});
