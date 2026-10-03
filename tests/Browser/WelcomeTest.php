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
        ->assertPresent('@welcome-hero-checks')
        ->assertSee('Tired of apps that never get past the demo?')
        ->assertSee('Fixed checks decide if it works.')
        ->assertSee('An AI agent on its own')
        ->assertSee('Undo any change you kept, even after others')
        ->assertSee('Download your code any time')
        ->click('@welcome-start')
        ->assertPathIs('/register');
});

it('keeps what a visitor typed for the new app form after they sign in', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->type('@welcome-idea', 'Customers order cakes for a pickup day.')
        ->click('@welcome-hero-start')
        ->assertPathIs('/projects')
        ->assertValue('#purpose', 'Customers order cakes for a pickup day.');
});

it('takes a signed-in owner to their apps', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->click('@welcome-apps')
        ->assertPathIs('/projects');
});
