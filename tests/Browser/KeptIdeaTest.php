<?php

use App\Models\User;

/*
| The idea a visitor types on the home page waits for them through sign-up.
| The link in the sign-up email opens a new tab, which starts with none of
| this tab's own storage, so the idea must not live there.
*/

it('keeps the idea for a new tab after the visitor signs up', function () {
    $page = visit('/')
        ->type('@welcome-idea', 'Customers order cakes for a pickup day.')
        ->click('@welcome-hero-start')
        ->assertPathIs('/register')
        ->assertSeeIn('@register-idea', 'Customers order cakes for a pickup day.');

    // Each visit() is a new browser, so the new tab is stood in for by
    // emptying what a new tab would not have.
    $page->script('sessionStorage.clear()');

    $this->actingAs(User::factory()->create());

    $page->navigate('/projects')
        ->assertValue('#purpose', 'Customers order cakes for a pickup day.');
});
