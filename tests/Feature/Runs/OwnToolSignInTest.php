<?php

namespace Tests\Feature\Runs;

use App\Actions\Projects\ConnectOwnTool;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Runs\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Passport;
use Tests\TestCase;

/*
| The owner's tool signed in through OAuth, as a connector in the Claude
| app, VS Code or Cursor: it takes an app's address, the owner presses
| Allow, and no token is ever copied.
*/
class OwnToolSignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tool_the_owner_signs_in_works_on_the_change_waiting_in_that_app()
    {
        $project = Project::factory()->create();
        $this->waitingRun(Project::factory()->create(), 'Trainers can cancel a class.');
        $this->waitingRun($project, 'Members can book a class.');

        // The whole way a connector goes: it finds where to sign in, signs
        // itself up, the owner allows it, and it trades the code for a pass.
        $address = route('mcp.app', ['project' => $project->uuid]);
        $this->postJson($address, $this->getTask())->assertUnauthorized();
        $metadata = $this->getJson('/.well-known/oauth-protected-resource/mcp/apps/'.$project->uuid)->assertOk()->json();
        $this->assertSame($address, $metadata['resource']);

        $client = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])
            ->assertCreated()
            ->json('client_id');

        $verifier = Str::random(64);
        $query = http_build_query([
            'client_id' => $client,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => Registrar::OAUTH_SCOPE,
            'state' => 'kept',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        $page = $this->actingAs($project->owner)->get('/oauth/authorize?'.$query)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/AllowTool')
                ->where('tool', 'Claude')
                ->where('returnsTo', 'claude.ai'));

        $back = $this->post('/oauth/authorize', ['auth_token' => $page->viewData('page')['props']['authToken']])->headers->get('Location');
        $this->assertStringStartsWith('https://claude.ai/api/mcp/auth_callback?', (string) $back);
        parse_str((string) parse_url((string) $back, PHP_URL_QUERY), $returned);
        $this->assertSame('kept', $returned['state']);

        $pass = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_verifier' => $verifier,
            'code' => $returned['code'],
        ])->assertOk()->json('access_token');

        // A tool call carries the pass and nothing else.
        $this->app['auth']->forgetGuards();
        $brief = (string) $this->withHeaders(['Authorization' => "Bearer {$pass}"])
            ->postJson($address, $this->getTask())
            ->assertOk()
            ->json('result.content.0.text');

        $this->assertStringContainsString('Members can book a class.', $brief);
        $this->assertStringNotContainsString('Trainers can cancel a class.', $brief);
        $this->assertStringContainsString('Your task code is', $brief);
    }

    public function test_the_app_stays_connected_while_its_signed_in_tool_works()
    {
        $project = Project::factory()->create();
        app(ConnectOwnTool::class)->handle($project);
        $project->tokens()->update(['expires_at' => now()->addDay()]);

        Passport::actingAs($project->owner, [Registrar::OAUTH_SCOPE]);
        $this->postJson(route('mcp.app', ['project' => $project->uuid]), $this->getTask())
            ->assertOk()
            ->assertSee('No change waits for you now.');

        $this->assertTrue($project->tokens()->sole()->expires_at->isAfter(now()->addDays((int) config('builder.agents.workers.project_days') - 1)));
    }

    public function test_a_signed_in_tool_opens_only_its_own_persons_apps()
    {
        $theirs = Project::factory()->create();
        $this->waitingRun($theirs, 'Members can book a class.');

        Passport::actingAs(User::factory()->create(), [Registrar::OAUTH_SCOPE]);

        // Signing in again would not help, so it is told no, not asked to.
        $this->postJson(route('mcp.app', ['project' => $theirs->uuid]), $this->getTask())->assertForbidden();
        $this->postJson(route('mcp.app', ['project' => 'not-an-app']), $this->getTask())->assertForbidden();
    }

    public function test_a_tool_without_a_good_sign_in_is_sent_to_sign_in()
    {
        $project = Project::factory()->create();
        $address = route('mcp.app', ['project' => $project->uuid]);

        $this->postJson($address, $this->getTask())
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/apps/'.$project->uuid).'"');

        // The token an app's terminal tool holds opens only the tools'
        // own address, not this one.
        $token = app(ConnectOwnTool::class)->handle($project);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson($address, $this->getTask())->assertUnauthorized();

        // A pass for something else, or of a person stopped from signing in.
        $this->app['auth']->forgetGuards();
        Passport::actingAs($project->owner, ['other']);
        $this->postJson($address, $this->getTask())->assertUnauthorized();

        $project->owner->forceFill(['suspended_at' => now()])->save();
        Passport::actingAs($project->owner->fresh(), [Registrar::OAUTH_SCOPE]);
        $this->postJson($address, $this->getTask())->assertUnauthorized();
    }

    public function test_only_the_tools_the_owner_uses_may_sign_up()
    {
        foreach (['https://claude.ai/api/mcp/auth_callback', 'http://localhost:51234/callback', 'http://127.0.0.1:33418', 'https://vscode.dev/redirect'] as $back) {
            $this->postJson('/oauth/register', ['client_name' => 'Tool', 'redirect_uris' => [$back]])->assertCreated();
        }

        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai.example.com/callback']])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);
    }

    public function test_letting_a_tool_in_needs_the_owner_signed_in()
    {
        $client = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->json('client_id');

        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => Registrar::OAUTH_SCOPE,
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]))->assertRedirect(route('login'));

        $discovery = $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
        $this->assertSame(['S256'], $discovery->json('code_challenge_methods_supported'));
        $this->assertSame(url('/oauth/register'), $discovery->json('registration_endpoint'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTask(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_task', 'arguments' => []]];
    }

    protected function waitingRun(Project $project, string $summary): Run
    {
        return Run::factory()->implementing()->for(FeatureRequest::factory()->for($project))->create([
            'driver' => 'worker',
            'plan' => (new Plan(summary: $summary, acceptanceCriteria: ['It works.'], tasks: ['Do it.']))->toArray(),
        ]);
    }
}
