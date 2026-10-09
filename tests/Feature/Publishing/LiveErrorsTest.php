<?php

namespace Tests\Feature\Publishing;

use App\Actions\Operations\FindAttentionItems;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LiveErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected Deployment $online;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'builder.publishing.laravel_cloud.token' => 'cloud-token',
            'builder.publishing.github.organization' => 'acme-apps',
            'builder.publishing.github.token' => 'github-token',
        ]);

        $this->project = Project::factory()->create([
            'host' => 'laravel_cloud',
            'host_state' => ['repository' => 'acme-apps/shop-1', 'application' => 'app-1', 'environment' => 'env-1'],
            'live_url' => 'https://shop.laravel.cloud',
        ]);
        $this->online = Deployment::factory()->for($this->project)->create([
            'user_id' => $this->project->user_id,
            'host' => 'laravel_cloud',
            'status' => DeploymentStatus::Published,
            'finished_at' => now()->subHour(),
        ]);
    }

    /**
     * Answer as Cloud's log listing would, one page per call.
     *
     * @param  list<list<array<string, mixed>>>  $pages
     */
    protected function fakeLogs(array $pages): void
    {
        $sequence = Http::sequence();

        foreach ($pages as $index => $entries) {
            $sequence->push(['data' => $entries, 'meta' => ['cursor' => $index < count($pages) - 1 ? 'next-'.$index : '', 'type' => 'application', 'from' => '', 'to' => '']]);
        }

        Http::fake(['cloud.laravel.com/api/environments/env-1/logs*' => $sequence]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function exception(string $class, string $message, string $at = '2026-09-27T10:00:00Z'): array
    {
        return ['message' => $message, 'level' => 'error', 'type' => 'exception', 'logged_at' => $at, 'data' => ['class' => $class, 'code' => 0, 'file' => 'app/Http/Controllers/OrderController.php', 'trace' => []]];
    }

    public function test_errors_of_the_online_version_are_counted_by_kind()
    {
        $this->fakeLogs([
            [
                $this->exception('ErrorException', 'Undefined array key 12'),
                ['message' => 'GET /orders 200', 'level' => 'info', 'type' => 'application', 'logged_at' => '2026-09-27T10:00:01Z', 'data' => null],
                $this->exception('ErrorException', 'Undefined array key 40', '2026-09-27T10:05:00Z'),
            ],
            [
                $this->exception('Illuminate\Database\QueryException', 'relation "orders" does not exist'),
            ],
        ]);

        $this->artisan('publishing:collect-errors')->assertSuccessful();

        $this->online->refresh();
        $this->assertSame(3, $this->online->liveErrorCount());
        $this->assertSame(['class' => 'ErrorException', 'message' => 'Undefined array key 12', 'count' => 2, 'last_at' => '2026-09-27T10:05:00Z'], $this->online->live_errors[0]);
        $this->assertNotNull($this->online->live_errors_checked_at);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'type=application') && $request->hasHeader('Authorization', 'Bearer cloud-token'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'cursor=next-0'));
    }

    public function test_each_check_reads_only_what_came_after_the_last_one()
    {
        Http::fake(['cloud.laravel.com/api/environments/env-1/logs*' => Http::sequence()
            ->push(['data' => [$this->exception('ErrorException', 'Undefined array key 12')], 'meta' => ['cursor' => '']])
            ->push(['data' => [$this->exception('ErrorException', 'Undefined array key 7')], 'meta' => ['cursor' => '']])]);
        $this->artisan('publishing:collect-errors');
        $checkedAt = $this->online->refresh()->live_errors_checked_at;

        $this->travel(5)->minutes();
        $this->artisan('publishing:collect-errors');

        $this->assertSame(2, $this->online->refresh()->liveErrorCount());
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'from='.$checkedAt->toIso8601String()));
    }

    public function test_the_owner_sees_how_often_it_went_wrong_but_not_the_error_text()
    {
        $this->online->update(['live_errors' => [['class' => 'ErrorException', 'message' => 'Undefined array key 12', 'count' => 4, 'last_at' => '2026-09-27T10:00:00Z']]]);

        $this->actingAs($this->project->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('publishing.deployments.0.problems', 4)
                ->missing('publishing.deployments.0.live_errors'));
    }

    public function test_operators_see_published_apps_raising_errors()
    {
        $this->online->update([
            'live_errors' => [['class' => 'ErrorException', 'message' => 'Undefined array key 12', 'count' => 4, 'last_at' => '2026-09-27T10:00:00Z']],
            'live_errors_checked_at' => now(),
        ]);

        $item = collect(app(FindAttentionItems::class)->handle(7)['items'])->firstWhere('key', 'published_errors');

        $this->assertSame(1, $item['count']);
        $this->assertSame('ErrorException Undefined array key 12 (×4)', $item['records'][0]['detail']);
    }

    public function test_only_the_version_online_now_is_checked()
    {
        $newer = Deployment::factory()->for($this->project)->create(['user_id' => $this->project->user_id, 'host' => 'laravel_cloud', 'status' => DeploymentStatus::Published, 'finished_at' => now()]);
        Deployment::factory()->for($this->project)->create(['user_id' => $this->project->user_id, 'host' => 'laravel_cloud', 'status' => DeploymentStatus::Failed]);
        $this->fakeLogs([[$this->exception('ErrorException', 'Undefined array key 12')]]);

        $this->artisan('publishing:collect-errors');

        $this->assertSame(0, $this->online->refresh()->liveErrorCount());
        $this->assertSame(1, $newer->refresh()->liveErrorCount());
        Http::assertSentCount(1);
    }

    public function test_an_app_on_the_owners_own_hosting_is_not_asked()
    {
        Http::fake();
        $this->project->update(['host' => 'git', 'deploy_remote' => 'git@example.com:acme/shop.git', 'deploy_branch' => 'live']);
        $this->online->update(['host' => 'git']);

        $this->artisan('publishing:collect-errors')->assertSuccessful();

        $this->assertNull($this->online->refresh()->live_errors_checked_at);
        Http::assertNothingSent();
    }

    public function test_when_cloud_does_not_answer_the_next_check_tries_the_same_window_again()
    {
        Http::fake(['cloud.laravel.com/*' => Http::response(['message' => 'Server error'], 500)]);

        $this->artisan('publishing:collect-errors')->assertSuccessful();

        $this->assertNull($this->online->refresh()->live_errors_checked_at);
    }
}
