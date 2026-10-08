<?php

use App\Models\User;

/*
| Bringing in an app reads a folder on our server, which is trusted
| operator input, never an owner's upload. Only an operator who asked for
| technical details is offered it, so an owner never meets a refusal.
*/

function bringer(bool $operator, bool $technical): User
{
    $user = User::factory()->create(['technical_details' => $technical]);
    config(['operations.operators' => $operator ? [$user->email] : []]);
    test()->actingAs($user);

    return $user;
}

it('offers an operator with technical details to bring in an app', function () {
    bringer(operator: true, technical: true);

    visit('/projects')
        ->assertVisible('[data-test="bring-in-open"]')
        ->assertNoJavaScriptErrors();
});

it('does not offer an owner to bring in an app, even with technical details', function () {
    bringer(operator: false, technical: true);

    visit('/projects')
        ->assertMissing('[data-test="bring-in-open"]')
        ->assertNoJavaScriptErrors();
});

it('keeps it out of an operator\'s simple view', function () {
    bringer(operator: true, technical: false);

    visit('/projects')
        ->assertMissing('[data-test="bring-in-open"]')
        ->assertNoJavaScriptErrors();
});
