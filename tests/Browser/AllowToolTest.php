<?php

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\ClientRepository;

/*
| The owner lets their own tool in from the Claude app, VS Code or Cursor.
| The buttons stay where they are while the choice is sent, and the page
| fits a phone whatever the tool calls itself.
*/

/**
 * The page a connector opens, for a tool that signed itself up.
 */
function allowToolPage(string $name = 'Claude'): string
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: $name,
        redirectUris: ['https://claude.ai/api/mcp/auth_callback'],
        confidential: false,
    );

    return '/oauth/authorize?'.http_build_query([
        'client_id' => $client->id,
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'response_type' => 'code',
        'scope' => Registrar::OAUTH_SCOPE,
        'state' => 'kept',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]);
}

// Each button's place and size. The forms do not send, so the page stays
// here instead of going on to the tool's site.
const ALLOW_TOOL_BUTTONS = "(() => { document.querySelectorAll('form').forEach((form) => form.addEventListener('submit', (event) => event.preventDefault(), true)); return [...document.querySelectorAll('button')].map((button) => { const box = button.getBoundingClientRect(); return [box.left, box.width, box.height].map(Math.round).join(); }).join(' '); })()";

const ALLOW_TOOL_PLACES = "[...document.querySelectorAll('button')].map((button) => { const box = button.getBoundingClientRect(); return [box.left, box.width, box.height].map(Math.round).join(); }).join(' ')";

it('keeps both buttons in place while Allow is sent', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(allowToolPage())
        ->assertSeeIn('[data-test="allow-tool-ask"]', 'Let Claude work on your apps? Then you go back to claude.ai.')
        ->assertSee('You can sign it out at any time in Settings, under Tools.');
    $before = $page->script(ALLOW_TOOL_BUTTONS);

    $page->click('[data-test="allow-tool"]')
        ->assertDisabled('[data-test="allow-tool"]')
        ->assertDisabled('[data-test="deny-tool"]')
        ->assertScript(ALLOW_TOOL_PLACES, $before)
        ->assertNoJavaScriptErrors();
});

it('keeps both buttons in place while Not now is sent', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(allowToolPage())->resize(390, 844);
    $before = $page->script(ALLOW_TOOL_BUTTONS);

    $page->click('[data-test="deny-tool"]')
        ->assertDisabled('[data-test="allow-tool"]')
        ->assertDisabled('[data-test="deny-tool"]')
        ->assertScript(ALLOW_TOOL_PLACES, $before)
        ->assertNoJavaScriptErrors();
});

it('fits a phone when the tool gives itself a long name', function () {
    $this->actingAs(User::factory()->create());

    visit(allowToolPage(str_repeat('x', 90)))
        ->resize(390, 844)
        ->assertSeeIn('[data-test="allow-tool-ask"]', str_repeat('x', 90))
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});
