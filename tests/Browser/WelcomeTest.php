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
        ->assertSee('Don’t just build a prototype.')
        ->assertSee('Checks decide if it works, not the')
        ->assertSee('Without checks')
        ->assertSee('See what your app does when things go')
        ->assertSee('Keep it, and the change shows what was tested')
        ->assertSee('Free to start. No card needed.')
        ->assertSee('Checked, with gaps')
        ->assertSee('Undo one change.')
        ->assertSee('Click a part of your app and change')
        ->click('@welcome-start')
        ->assertPathIs('/register');
});

it('plays the checks once a visitor scrolls to them', function () {
    $page = visit('/');

    $page->script("document.getElementById('different').scrollIntoView()");

    $page->wait(6)
        ->assertSee('The checks passed. It is ready for you to keep.');
});

it('opens the real designer once a visitor scrolls to it', function () {
    $page = visit('/');

    $page->script("document.querySelector('[data-test=welcome-designer]').scrollIntoView()");

    $page->wait(2)->assertPresent('@welcome-designer-frame');
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
