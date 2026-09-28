<?php

use App\Models\User;

/*
| What a visitor learns before signing up: what they get that is different,
| shown as an example change and three plain promises.
*/

it('shows a visitor the example change and the promises, and starts them on an app', function () {
    visit('/')
        ->assertPresent('@welcome-example')
        ->assertSee('Checked before you see it')
        ->assertSee('Undo what you kept')
        ->assertSee('Yours to take')
        ->click('@welcome-start')
        ->assertPathIs('/register');
});

it('takes a signed-in owner to their apps', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->click('@welcome-apps')
        ->assertPathIs('/projects');
});
