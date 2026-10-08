<?php

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;

uses(PreparesRuns::class);

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

it('keeps what the owner types in the new-app box when the page reloads', function () {
    $this->actingAs(User::factory()->create());

    visit('/projects')
        ->type('#purpose', 'A small bakery takes cake orders online.')
        ->navigate('/projects')
        ->assertValue('#purpose', 'A small bakery takes cake orders online.')
        // Kept until the app starts, so a second reload finds it too.
        ->navigate('/projects')
        ->assertValue('#purpose', 'A small bakery takes cake orders online.');
});

it('forgets the idea once the app has started from it', function () {
    Queue::fake();
    config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
    $this->actingAs(User::factory()->create());

    visit('/projects')
        ->type('#purpose', 'A small bakery takes cake orders online.')
        ->click('[data-test="start-project-button"]')
        ->assertPathBeginsWith('/projects/')
        ->navigate('/projects')
        ->assertValue('#purpose', '');
});

it('still takes typing when the browser blocks its storage', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/projects');
    $page->script("Object.defineProperty(window, 'localStorage', { get() { throw new Error('blocked'); } })");

    $page->type('#purpose', 'A small bakery takes cake orders online.')
        ->assertValue('#purpose', 'A small bakery takes cake orders online.')
        ->assertNoJavaScriptErrors();
});
