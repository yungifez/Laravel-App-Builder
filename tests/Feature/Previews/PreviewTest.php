<?php

namespace Tests\Feature\Previews;

use App\Enums\PreviewStatus;
use App\Jobs\StartPreview;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\User;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PreviewTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();

        config([
            'builder.preview.workspace_driver' => 'fake',
            'builder.preview.domain' => 'preview.test',
            'builder.preview.public_port' => null,
            'builder.preview.setup' => [
                ['name' => 'Install', 'command' => ['composer', 'install'], 'timeout' => 600],
            ],
        ]);
    }

    public function test_the_owner_starts_a_preview_of_the_change_and_its_lineage()
    {
        Http::fake(['*/up' => Http::response('ok')]);
        $parent = FeatureRequest::factory()->generated()->create(['patch' => 'PARENT PATCH']);
        $request = FeatureRequest::factory()->generated()->for($parent->project)->create(['parent_id' => $parent->id, 'patch' => 'CHILD PATCH']);

        $this->actingAs($parent->project->owner)
            ->post(route('feature-requests.previews.store', $request))
            ->assertRedirect(route('feature-requests.show', $request));

        $preview = $request->previews()->sole();
        $this->assertSame(PreviewStatus::Ready, $preview->status);
        $this->assertMatchesRegularExpression('/^p[a-z0-9]{31}$/', $preview->host);

        $workspaceId = $this->driver->copies[0]['workspace'];
        $this->assertSame('PARENT PATCH', $this->driver->files["{$workspaceId}:.builder/01.patch"]);
        $this->assertSame('CHILD PATCH', $this->driver->files["{$workspaceId}:.builder/02.patch"]);
        $this->assertContains(['composer', 'install'], array_column($this->driver->executed, 'command'));

        $service = $this->driver->services[0];
        $this->assertSame($preview->port, $service['port']);
        $this->assertContains("APP_URL=http://{$preview->host}.preview.test", $service['command']);
        $this->assertContains('MAIL_MAILER=log', $service['command']);
        $this->assertSame(['php', '-S', "127.0.0.1:{$preview->port}"], array_slice($service['command'], array_search('php', $service['command'], true), 3));
        $this->assertSame("http://{$workspaceId}.test:{$preview->port}", $preview->upstream_url);

        $this->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('preview.status', 'ready'));
    }

    public function test_a_duplicate_start_leaves_a_running_preview_alone()
    {
        $preview = Preview::factory()->ready()->create();

        StartPreview::dispatchSync($preview);

        $this->assertSame([], $this->driver->created);
        $this->assertSame(PreviewStatus::Ready, $preview->refresh()->status);
    }

    public function test_a_preview_that_fails_to_start_reports_why_and_removes_its_workspace()
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: $command === ['composer', 'install'] ? 1 : 0,
            output: '',
            errorOutput: $command === ['composer', 'install'] ? 'Your lock file is out of date.' : '',
            durationMs: 5,
        );
        $request = FeatureRequest::factory()->generated()->create();

        $this->actingAs($request->project->owner)->post(route('feature-requests.previews.store', $request));

        $preview = $request->previews()->sole();
        $this->assertSame(PreviewStatus::Failed, $preview->status);
        $this->assertStringContainsString('The setup step "Install" failed.', (string) $preview->error);
        $this->assertStringContainsString('Your lock file is out of date.', (string) $preview->error);
        $this->assertCount(1, $this->driver->destroyed);
        $this->assertSame([], $this->driver->services);
    }

    public function test_starting_a_preview_again_stops_the_running_one()
    {
        Http::fake(['*/up' => Http::response('ok')]);
        $running = Preview::factory()->ready()->create();

        $this->actingAs($running->featureRequest->project->owner)
            ->post(route('feature-requests.previews.store', $running->featureRequest));

        $this->assertSame(PreviewStatus::Stopped, $running->refresh()->status);
        $this->assertSame(PreviewStatus::Ready, $running->featureRequest->previews()->latest('id')->first()?->status);
    }

    public function test_opening_a_preview_hands_the_owner_a_single_use_grant_that_becomes_a_preview_session()
    {
        $preview = Preview::factory()->ready()->create();

        $location = $this->actingAs($preview->featureRequest->project->owner)
            ->get(route('previews.show', $preview))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith("http://{$preview->host}.preview.test/__builder/session?grant=", (string) $location);

        $exchange = $this->get((string) $location);
        $exchange->assertRedirect('/');
        $cookie = collect($exchange->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'builder_preview');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain(), 'The session cookie must stay on the preview host.');

        // The grant cannot be used twice.
        $this->get((string) $location)->assertForbidden();
    }

    public function test_an_expired_grant_is_refused()
    {
        $preview = Preview::factory()->ready()->create();
        $location = $this->actingAs($preview->featureRequest->project->owner)->get(route('previews.show', $preview))->headers->get('Location');

        $this->travel(61)->seconds();

        $this->get((string) $location)->assertForbidden();
    }

    public function test_other_users_cannot_open_start_or_stop_a_preview()
    {
        $preview = Preview::factory()->ready()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('previews.show', $preview))->assertForbidden();
        $this->actingAs($stranger)->post(route('feature-requests.previews.store', $preview->featureRequest))->assertForbidden();
        $this->actingAs($stranger)->delete(route('previews.destroy', $preview))->assertForbidden();

        $this->assertNull($preview->refresh()->grant_hash);
        $this->assertSame(PreviewStatus::Ready, $preview->status);
    }

    public function test_session_holders_requests_are_relayed_to_the_app_as_the_preview_host()
    {
        $preview = $this->previewWithSession('secret-value');
        Http::fake(['http://127.0.0.1:20001/*' => Http::response('<h1>Teams</h1>', 201, [
            'Content-Type' => 'text/html',
            'Set-Cookie' => 'app_session=abc; path=/; httponly',
            'Location' => '/teams/1',
        ])]);

        $response = $this->previewRequest('POST', "http://{$preview->host}.preview.test/teams?page=2", ['builder_preview' => 'secret-value', 'app_session' => 'old'], ['name' => 'Acme']);

        $response->assertStatus(201);
        $this->assertSame('<h1>Teams</h1>', $response->getContent());
        $this->assertSame('/teams/1', $response->headers->get('Location'));
        $this->assertSame('app_session', $response->headers->getCookies()[0]->getName());
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'http://127.0.0.1:20001/teams?page=2'
            && $request->method() === 'POST'
            && $request->header('Host') === ["{$preview->host}.preview.test"]
            && $request->header('Cookie') === ['app_session=old']
            && $request->body() === 'name=Acme');
    }

    public function test_file_uploads_are_relayed_as_multipart_requests()
    {
        $preview = $this->previewWithSession('secret-value');
        Http::fake(['*' => Http::response('ok')]);

        $this->call('POST', "http://{$preview->host}.preview.test/avatar", ['team' => ['name' => 'Acme']], ['builder_preview' => 'secret-value'], [
            'photo' => UploadedFile::fake()->createWithContent('logo.png', 'PNGDATA'),
        ])->assertOk();

        Http::assertSent(fn (ClientRequest $request) => $request->isMultipart()
            && collect($request->data())->contains(fn (array $part) => $part['name'] === 'team[name]' && $part['contents'] === 'Acme')
            && collect($request->data())->contains(fn (array $part) => $part['name'] === 'photo' && ($part['filename'] ?? null) === 'logo.png'));
    }

    public function test_requests_without_the_preview_session_are_refused_and_never_reach_the_app()
    {
        $preview = $this->previewWithSession('secret-value');
        Http::fake();

        $this->call('GET', "http://{$preview->host}.preview.test/")->assertForbidden();
        $this->previewRequest('GET', "http://{$preview->host}.preview.test/", ['builder_preview' => 'wrong'])->assertForbidden();

        $preview->update(['session_expires_at' => now()->subMinute()]);
        $this->previewRequest('GET', "http://{$preview->host}.preview.test/", ['builder_preview' => 'secret-value'])->assertForbidden();

        $other = $this->previewWithSession('other-secret');
        $this->previewRequest('GET', "http://{$other->host}.preview.test/", ['builder_preview' => 'secret-value'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_preview_host_never_serves_the_control_planes_pages_even_to_a_signed_in_owner()
    {
        $preview = Preview::factory()->ready()->create();
        Http::fake();

        $this->actingAs($preview->featureRequest->project->owner)
            ->get("http://{$preview->host}.preview.test/dashboard")
            ->assertForbidden()
            ->assertSee('Open this preview from the builder.');

        $this->get("http://{$preview->host}.preview.test/previews/{$preview->id}")->assertForbidden();
        $this->get('http://unknown.preview.test/')->assertNotFound();
        $this->get('http://p-evil.preview.test/')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_an_unreachable_app_is_reported_as_a_bad_gateway()
    {
        $preview = $this->previewWithSession('secret-value');
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->previewRequest('GET', "http://{$preview->host}.preview.test/", ['builder_preview' => 'secret-value'])
            ->assertStatus(502);
    }

    public function test_the_owner_can_stop_a_preview_and_its_session_ends()
    {
        $preview = $this->previewWithSession('secret-value');
        Http::fake();

        $this->actingAs($preview->featureRequest->project->owner)
            ->delete(route('previews.destroy', $preview))
            ->assertRedirect(route('feature-requests.show', $preview->featureRequest));

        $preview->refresh();
        $this->assertSame(PreviewStatus::Stopped, $preview->status);
        $this->assertNull($preview->session_hash);
        $this->previewRequest('GET', "http://{$preview->host}.preview.test/", ['builder_preview' => 'secret-value'])->assertForbidden();
    }

    public function test_expired_and_idle_previews_are_reaped()
    {
        $expired = Preview::factory()->ready()->create(['expires_at' => now()->subMinute()]);
        $idle = Preview::factory()->ready()->create(['last_seen_at' => now()->subMinutes(31)]);
        $active = Preview::factory()->ready()->create(['last_seen_at' => now()->subMinutes(5)]);

        $this->artisan('previews:reap')->assertSuccessful();

        $this->assertSame(PreviewStatus::Stopped, $expired->refresh()->status);
        $this->assertSame(PreviewStatus::Stopped, $idle->refresh()->status);
        $this->assertSame(PreviewStatus::Ready, $active->refresh()->status);
    }

    /**
     * Send a request to a preview host with the given cookies, as a browser
     * would (both parsed and in the raw Cookie header).
     *
     * @param  array<string, string>  $cookies
     * @param  array<string, string>  $parameters
     */
    protected function previewRequest(string $method, string $url, array $cookies = [], array $parameters = []): TestResponse
    {
        $header = implode('; ', array_map(fn (string $name, string $value) => "{$name}={$value}", array_keys($cookies), $cookies));

        return $this->call($method, $url, $parameters, $cookies, [], $header === '' ? [] : ['HTTP_COOKIE' => $header]);
    }

    /**
     * Create a running preview with a session for the given cookie value.
     */
    protected function previewWithSession(string $secret): Preview
    {
        return Preview::factory()->ready()->create([
            'session_hash' => hash('sha256', $secret),
            'session_expires_at' => now()->addHour(),
        ]);
    }
}
