<?php

use App\Models\User;

/*
| What a visitor learns before signing up: fixed checks, not the AI, decide
| whether a change works, and what they keep. The box they type in first
| carries their idea into the new-app form.
*/

it('shows a visitor what makes it different and what they keep, and starts them on an app', function () {
    visit('/')
        ->assertPresent('@welcome-example')
        ->assertPresent('@welcome-different')
        ->assertPresent('@welcome-stage')
        ->assertSeeIn('@welcome-stage', 'Let customers cancel a booking up to a day before.')
        ->assertSee('Tired of apps that never get past the demo?')
        ->assertSee('Fixed checks decide if it works.')
        ->assertSee('An AI agent on its own')
        ->assertSee('It tells you how it knows.')
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

it('starts an app from the box at the end of the page too', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->type('@welcome-end-idea', 'Neighbours lend each other tools.')
        ->click('@welcome-end-start')
        ->assertPathIs('/projects')
        ->assertValue('#purpose', 'Neighbours lend each other tools.');
});

it('takes a signed-in owner to their apps', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->click('@welcome-apps')
        ->assertPathIs('/projects');
});
