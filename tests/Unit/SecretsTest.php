<?php

use App\Ai\Middleware\RedactSecrets;
use App\Support\Secrets;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

// Built at run time, so no key-shaped text sits in the repository.
function fakeKey(string $prefix, int $length = 40): string
{
    return $prefix.str_repeat('a1B2', intdiv($length, 4));
}

it('replaces each live key and keeps the rest of the text', function () {
    $anthropic = fakeKey('sk-ant-');
    $stripe = fakeKey('sk_live_', 24);

    expect(Secrets::redact("Use {$anthropic} for the AI and {$stripe} for payments."))
        ->toBe('Use [secret removed] for the AI and [secret removed] for payments.')
        ->and(Secrets::found("key: {$stripe}"))->toBeTrue()
        ->and(Secrets::found('Let owners invite people by email.'))->toBeFalse()
        ->and(Secrets::redact('A sk_test_ key and a short sk-123 stay.'))->toBe('A sk_test_ key and a short sk-123 stay.');
});

it('removes a whole private key, not only its first line', function () {
    $key = "-----BEGIN RSA PRIVATE KEY-----\nMIIEow\nIBAAK\n-----END RSA PRIVATE KEY-----";

    expect(Secrets::redact("Deploy with this:\n{$key}\nThanks."))->toBe("Deploy with this:\n[secret removed]\nThanks.");
});

it('keeps live keys out of what an agent sends to its model', function () {
    $key = fakeKey('ghp_');
    $step = new PendingStep(0, true, 'anthropic', 'model', "Notes say {$key}.", [
        new UserMessage("Connect GitHub with {$key}"),
        $untouched = new AssistantMessage('Done.'),
    ], [], null, null);

    $sent = (new RedactSecrets)->handle($step, fn (PendingStep $step) => new StepResult(
        new StepResponse('', [], FinishReason::Stop, new TextUsage, new Meta('anthropic', 'model')),
        $step,
    ))->step;

    expect($sent->instructions)->toBe('Notes say [secret removed].')
        ->and($sent->messages[0]->content)->toBe('Connect GitHub with [secret removed]')
        ->and($sent->messages[1])->toBe($untouched)
        ->and($step->messages[0]->content)->toBe("Connect GitHub with {$key}");
});
