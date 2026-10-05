<?php

use App\Features\ProtectedInputs;

it('finds touched paths that are or sit inside a protected path', function () {
    $protected = ['tests/Acceptance', 'phpunit.xml', '.github/'];

    expect(ProtectedInputs::touched(['phpunit.xml', 'tests/Acceptance/InviteTest.php', '.github/workflows/ci.yml', 'app/Models/User.php', 'phpunit.xml.bak', 'tests/AcceptanceHelpers.php'], $protected))
        ->toBe(['phpunit.xml', 'tests/Acceptance/InviteTest.php', '.github/workflows/ci.yml']);
});

it('sees a change to scripts but not to the rest of a manifest', function () {
    $before = json_encode(['require' => ['a/b' => '^1'], 'scripts' => ['build' => 'vite build']]);

    expect(ProtectedInputs::scriptsChanged($before, json_encode(['require' => ['a/b' => '^2'], 'scripts' => ['build' => 'vite build']])))->toBeFalse()
        ->and(ProtectedInputs::scriptsChanged($before, json_encode(['scripts' => ['build' => 'true']])))->toBeTrue()
        ->and(ProtectedInputs::scriptsChanged(null, json_encode(['scripts' => []])))->toBeFalse()
        ->and(ProtectedInputs::scriptsChanged('not json', null))->toBeFalse();
});
