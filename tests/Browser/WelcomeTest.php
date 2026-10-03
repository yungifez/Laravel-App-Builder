<?php

use App\Models\User;

/*
| What a visitor learns before signing up: what makes this different from
| other AI app builders, shown on real screens, and what they keep.
*/

it('shows a visitor what makes it different and what they keep, and starts them on an app', function () {
    visit('/')
        ->assertPresent('@welcome-example')
        ->assertPresent('@welcome-different')
        ->assertSee('I understand your app.')
        ->assertSee('Keeps a written understanding of your app')
        ->assertSee('Undo any change you kept.')
        ->assertSee('Your code. Any time.')
        ->click('@welcome-start')
        ->assertPathIs('/register');
});

it('takes a signed-in owner to their apps', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->click('@welcome-apps')
        ->assertPathIs('/projects');
});
