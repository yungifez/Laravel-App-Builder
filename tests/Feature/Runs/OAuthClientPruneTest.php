<?php

namespace Tests\Feature\Runs;

use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

/*
| A tool that signs itself up (the Claude app, VS Code, Cursor) gets a new
| OAuth client on each fresh connection. The ones nobody uses any more are
| pruned, with what they were given; the ones still connected stay.
*/
class OAuthClientPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.agents.workers.project_days' => 30]);
    }

    /**
     * A client as /oauth/register makes it: public and without an owner.
     */
    protected function signedUp(string $name): Client
    {
        return app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: $name,
            redirectUris: ['https://claude.ai/api/mcp/auth_callback'],
            confidential: false,
        );
    }

    /**
     * A pass for the client, with the refresh token that renews it.
     */
    protected function pass(Client $client, bool $revoked = false, int $refreshDays = 30): Token
    {
        $token = Token::query()->forceCreate([
            'id' => Str::random(80),
            'user_id' => User::factory()->create()->id,
            'client_id' => $client->id,
            'revoked' => $revoked,
            'expires_at' => now()->addHour(),
        ]);
        RefreshToken::query()->forceCreate([
            'id' => Str::random(80),
            'access_token_id' => $token->id,
            'revoked' => $revoked,
            'expires_at' => now()->addDays($refreshDays),
        ]);

        return $token;
    }

    /**
     * @return list<string>
     */
    protected function prune(): array
    {
        Artisan::call('model:prune', ['--model' => [OAuthClient::class]]);

        return Client::query()->orderBy('name')->pluck('name')->all();
    }

    public function test_a_client_that_never_got_a_pass_goes_after_a_day()
    {
        $this->signedUp('Unused');
        $this->travel(25)->hours();
        $this->signedUp('Just signed up');

        $this->assertSame(['Just signed up'], $this->prune());
    }

    public function test_a_client_stays_while_its_tool_can_still_renew_its_pass()
    {
        // The pass itself lapses in an hour; the refresh token keeps the
        // tool connected for the app's days.
        $this->pass($this->signedUp('Renewing'));
        $this->travel(29)->days();

        $this->assertSame(['Renewing'], $this->prune());
    }

    public function test_a_client_goes_with_its_passes_once_they_ended_longer_ago_than_a_tool_stays_connected()
    {
        $lapsed = $this->signedUp('Lapsed');
        $this->pass($lapsed);
        AuthCode::query()->forceCreate(['id' => Str::random(80), 'user_id' => User::factory()->create()->id, 'client_id' => $lapsed->id, 'revoked' => true, 'expires_at' => now()->addMinutes(10)]);
        $revoked = $this->signedUp('Revoked');
        $this->pass($revoked);
        $this->travel(31)->days();
        // A pass revoked a day ago ended then, not when it was made.
        $this->pass($revoked, revoked: true, refreshDays: 0)->forceFill(['updated_at' => now()->subDay()])->save();
        $this->travel(31)->days();

        $this->assertSame([], $this->prune());
        $this->assertSame([0, 0, 0], [Token::query()->count(), RefreshToken::query()->count(), AuthCode::query()->count()]);
    }

    public function test_a_pass_revoked_recently_keeps_its_client()
    {
        $revoked = $this->signedUp('Revoked yesterday');
        $this->travel(40)->days();
        $this->pass($revoked, revoked: true, refreshDays: 0)->forceFill(['updated_at' => now()->subDay()])->save();

        $this->assertSame(['Revoked yesterday'], $this->prune());
    }

    public function test_clients_that_did_not_sign_themselves_up_are_never_pruned()
    {
        $clients = app(ClientRepository::class);
        $clients->createAuthorizationCodeGrantClient('Owned', ['https://example.com/callback'], confidential: false, user: User::factory()->create());
        $clients->createAuthorizationCodeGrantClient('Confidential', ['https://example.com/callback']);
        $this->travel(90)->days();

        $this->assertSame(['Confidential', 'Owned'], $this->prune());
    }

    public function test_the_days_a_tool_stays_connected_come_from_config()
    {
        config(['builder.agents.workers.project_days' => 5]);
        $this->pass($this->signedUp('Short'), refreshDays: 5);
        $this->travel(11)->days();

        $this->assertSame([], $this->prune());
    }

    public function test_lapsed_passes_are_purged_daily_once_no_live_refresh_token_needs_them()
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'passport:purge'));

        $this->assertCount(1, $events);
        $this->assertStringEndsWith('passport:purge --expired --hours=720', (string) $events->sole()->command);
        $this->assertSame('0 0 * * *', $events->sole()->expression);
    }
}
