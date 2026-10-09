<?php

namespace Tests\Feature\Settings;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

/*
| The owner sees the tools they let in through OAuth (the Claude app,
| VS Code, Cursor) and signs one out of their apps.
*/
class ToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function tool(string $name): Client
    {
        return app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: $name,
            redirectUris: ['https://claude.ai/api/mcp/auth_callback'],
            confidential: false,
        );
    }

    /**
     * A pass the person gave the tool, with the refresh token that renews it.
     */
    protected function pass(User $user, Client $client, int $passMinutes = 60, int $refreshDays = 30, bool $revoked = false): Token
    {
        $token = Token::query()->forceCreate([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'revoked' => $revoked,
            'expires_at' => now()->addMinutes($passMinutes),
        ]);
        RefreshToken::query()->forceCreate([
            'id' => Str::random(80),
            'access_token_id' => $token->id,
            'revoked' => $revoked,
            'expires_at' => now()->addDays($refreshDays),
        ]);

        return $token;
    }

    public function test_the_owner_sees_each_tool_they_let_in_and_when_it_was_last_used()
    {
        $owner = User::factory()->create();
        $claude = $this->tool('Claude');
        $this->pass($owner, $claude);
        // Cursor's pass lapsed, but it can still renew it.
        $this->pass($owner, $this->tool('Cursor'), passMinutes: -10, refreshDays: 1);
        $this->travel(2)->hours();
        $this->pass($owner, $claude);

        $this->actingAs($owner)
            ->get(route('tools.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Tools')
                ->has('tools', 2)
                // The one used last comes first.
                ->where('tools.0.name', 'Claude')
                ->where('tools.0.allowed', '2 hours ago')
                ->where('tools.0.used', '0 seconds ago')
                ->where('tools.1.name', 'Cursor'));
    }

    public function test_tools_that_can_no_longer_reach_the_apps_or_belong_to_someone_else_are_not_listed()
    {
        $owner = User::factory()->create();
        $this->pass($owner, $this->tool('Lapsed'), passMinutes: -10, refreshDays: -1);
        $this->pass($owner, $this->tool('Revoked'), revoked: true);
        $this->pass(User::factory()->create(), $this->tool('Theirs'));

        $this->actingAs($owner)
            ->get(route('tools.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('tools', []));
    }

    public function test_signing_a_tool_out_revokes_only_this_persons_passes()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $claude = $this->tool('Claude');
        $mine = $this->pass($owner, $claude);
        $theirs = $this->pass($other, $claude);
        $cursor = $this->pass($owner, $this->tool('Cursor'));

        $this->actingAs($owner)
            ->from(route('tools.edit'))
            ->delete(route('tools.destroy', $claude->id))
            ->assertRedirect(route('tools.edit'));

        $this->assertTrue($mine->refresh()->revoked);
        $this->assertTrue(RefreshToken::query()->where('access_token_id', $mine->id)->sole()->revoked);
        $this->assertFalse($theirs->refresh()->revoked);
        $this->assertFalse(RefreshToken::query()->where('access_token_id', $theirs->id)->sole()->revoked);
        $this->assertFalse($cursor->refresh()->revoked);
    }

    public function test_a_tool_signed_out_can_no_longer_reach_the_app()
    {
        $project = Project::factory()->create();
        $address = route('mcp.app', ['project' => $project->uuid]);
        [$client, $pass] = $this->signIn($project->owner);
        $call = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_task', 'arguments' => []]];

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$pass}"])->postJson($address, $call)->assertOk();

        $this->actingAs($project->owner)->delete(route('tools.destroy', $client));

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$pass}"])->postJson($address, $call)->assertUnauthorized();
    }

    /**
     * Sign a tool in the whole way a connector does, and give back its
     * client and its pass.
     *
     * @return array{string, string}
     */
    protected function signIn(User $owner): array
    {
        $back = 'https://claude.ai/api/mcp/auth_callback';
        $client = (string) $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$back]])->assertCreated()->json('client_id');
        $verifier = Str::random(64);

        $page = $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client,
            'redirect_uri' => $back,
            'response_type' => 'code',
            'scope' => Registrar::OAUTH_SCOPE,
            'state' => 'kept',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]))->assertOk();
        $location = (string) $this->post('/oauth/authorize', ['auth_token' => $page->viewData('page')['props']['authToken']])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $returned);

        $pass = (string) $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client,
            'redirect_uri' => $back,
            'code_verifier' => $verifier,
            'code' => $returned['code'],
        ])->assertOk()->json('access_token');

        return [$client, $pass];
    }
}
