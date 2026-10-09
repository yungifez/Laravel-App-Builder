<?php

namespace Tests\Feature\Previews;

use App\Actions\Previews\RequestProjectPreview;
use App\Enums\PreviewStatus;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Previews\PreviewGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class ShareAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.preview.domain' => 'preview.test', 'builder.preview.public_port' => null]);
    }

    public function test_anyone_with_the_shared_link_can_try_the_app_without_ending_the_owners_sessions()
    {
        Http::fake(['http://127.0.0.1:20001/*' => Http::response('<h1>Classes</h1>', 200, ['Content-Type' => 'text/html'])]);
        $preview = Preview::factory()->editable()->ready()->create();
        $project = $preview->project;

        $this->actingAs($project->owner)->post(route('projects.share.store', $project))->assertRedirect();
        $link = (string) $project->refresh()->share_token;
        $this->assertSame(now()->addDays(7)->toDateString(), $project->share_expires_at?->toDateString());

        $this->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('project.share.url', route('shared-apps.show', $link)));

        // Addresses are made before the preview host is visited.
        [$shared, $store, $destroy] = [route('shared-apps.show', $link), route('projects.share.store', $project), route('projects.share.destroy', $project)];

        // The owner has the app open in the builder.
        $owner = $this->openSession((string) $this->get(route('previews.show', $preview))->headers->get('Location'));

        // Someone without an account opens the link.
        auth()->logout();
        $grant = (string) $this->get($shared)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith("http://{$preview->host}.preview.test/__builder/session?grant=", $grant);
        $this->assertNull($preview->refresh()->grant_hash);
        $guest = $this->openSession($grant);

        $this->previewRequest("http://{$preview->host}.preview.test/", $guest)->assertOk()->assertSee('Classes');
        $this->previewRequest("http://{$preview->host}.preview.test/", $owner)->assertOk();
        $this->assertCount(1, Cache::get(PreviewGateway::sharedSessionsKey($preview)));

        // Sharing again keeps the link the owner already sent.
        $this->actingAs($project->owner)->post($store);
        $this->assertSame($link, $project->refresh()->share_token);

        // Once the owner stops sharing, the link and its sessions end, and
        // the owner's own session goes on.
        $this->delete($destroy)->assertRedirect();
        auth()->logout();
        $this->get($shared)->assertNotFound();
        $this->previewRequest("http://{$preview->host}.preview.test/", $guest)->assertForbidden();
        $this->previewRequest("http://{$preview->host}.preview.test/", $owner)->assertOk();
    }

    public function test_a_shared_link_ends_when_it_expires()
    {
        $project = Project::factory()->create();
        $this->actingAs($project->owner)->post(route('projects.share.store', $project));
        $link = (string) $project->refresh()->share_token;
        auth()->logout();

        $this->travel(8)->days();

        $this->get(route('shared-apps.show', $link))->assertNotFound();
        $this->get(route('shared-apps.show', str_repeat('a', 40)))->assertNotFound();
    }

    public function test_stopping_sharing_revokes_unused_grants_even_after_the_owner_shares_again()
    {
        $preview = Preview::factory()->editable()->ready()->create();
        $project = $preview->project;
        [$store, $destroy] = [route('projects.share.store', $project), route('projects.share.destroy', $project)];
        $this->actingAs($project->owner)->post($store)->assertRedirect();
        $shared = route('shared-apps.show', $project->refresh()->share_token);
        auth()->logout();
        $pending = (string) $this->get($shared)->assertRedirect()->headers->get('Location');
        $secondPending = (string) $this->get($shared)->assertRedirect()->headers->get('Location');

        $this->actingAs($project->owner)->delete($destroy)->assertRedirect();
        auth()->logout();
        $this->get($pending)->assertForbidden();
        $this->assertNull(Cache::get(PreviewGateway::sharedSessionsKey($preview)));

        $this->actingAs($project->owner)->post($store)->assertRedirect();
        $newShared = route('shared-apps.show', $project->refresh()->share_token);
        auth()->logout();
        $this->get($secondPending)->assertForbidden();
        $newGrant = (string) $this->get($newShared)->assertRedirect()->headers->get('Location');
        $this->assertNotSame('', $this->openSession($newGrant));
        $this->assertCount(1, Cache::get(PreviewGateway::sharedSessionsKey($preview)));
    }

    public function test_only_the_owner_can_share_the_app_or_stop_sharing_it()
    {
        $project = Project::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('projects.share.store', $project))->assertForbidden();
        $this->actingAs($stranger)->delete(route('projects.share.destroy', $project))->assertForbidden();

        $this->assertNull($project->refresh()->share_token);
    }

    public function test_an_unused_grant_cannot_outlive_the_shared_link_it_came_from()
    {
        $preview = Preview::factory()->editable()->ready()->create();
        $project = $preview->project;
        $this->actingAs($project->owner)->post(route('projects.share.store', $project))->assertRedirect();
        $shared = route('shared-apps.show', $project->refresh()->share_token);
        $project->update(['share_expires_at' => now()->addSeconds(5)]);
        auth()->logout();
        $pending = (string) $this->get($shared)->assertRedirect()->headers->get('Location');

        $this->travel(6)->seconds();

        $this->get($pending)->assertForbidden();
        $this->assertNull(Cache::get(PreviewGateway::sharedSessionsKey($preview)));
    }

    public function test_a_shared_app_that_is_asleep_is_started_once_while_people_wait()
    {
        $project = Project::factory()->create();
        $this->actingAs($project->owner)->post(route('projects.share.store', $project));
        $link = (string) $project->refresh()->share_token;
        auth()->logout();
        $this->mock(RequestProjectPreview::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')
            ->once()
            ->andReturnUsing(fn (Project $project) => Preview::factory()->editable()->for($project)->create(['status' => PreviewStatus::Starting])));

        foreach ([1, 2] as $visit) {
            $this->get(route('shared-apps.show', $link))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('shared-apps/Show')
                    ->where('name', $project->name));
        }
    }

    /**
     * Exchange a grant for a session, as the browser would, and return the
     * session's cookie.
     */
    protected function openSession(string $grant): string
    {
        $cookie = collect($this->get($grant)->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'builder_preview');

        return (string) $cookie?->getValue();
    }

    /**
     * Request a page of the preview with a session cookie.
     */
    protected function previewRequest(string $url, string $session): TestResponse
    {
        return $this->call('GET', $url, [], ['builder_preview' => $session], [], ['HTTP_COOKIE' => "builder_preview={$session}"]);
    }
}
