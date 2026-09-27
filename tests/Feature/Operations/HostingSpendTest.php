<?php

namespace Tests\Feature\Operations;

use App\Actions\Operations\SummarizeHostingSpend;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HostingSpendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.publishing.laravel_cloud.token' => 'cloud-token']);
    }

    /**
     * Answer as Cloud's usage report would.
     *
     * @param  list<array{identifier: string, total_cost_cents: int}>  $applications
     */
    protected function fakeUsage(array $applications, int $total = 1234): void
    {
        Http::fake(['cloud.laravel.com/api/usage' => Http::response([
            'data' => [
                'summary' => ['current_spend_cents' => $total],
                'application_totals' => ['total_cost_cents' => $total, 'applications' => $applications],
            ],
            'meta' => ['currency' => 'USD'],
        ])]);
    }

    public function test_operators_see_what_each_hosted_app_costs_us_costliest_first()
    {
        $shop = Project::factory()->create(['name' => 'Acme Shop', 'host' => 'laravel_cloud', 'host_state' => ['application' => 'app-1']]);
        $blog = Project::factory()->create(['name' => 'Blog', 'host' => 'laravel_cloud', 'host_state' => ['application' => 'app-2']]);
        $this->fakeUsage([
            ['identifier' => 'app-1', 'total_cost_cents' => 300],
            ['identifier' => 'blog-'.$blog->id, 'total_cost_cents' => 800],
            ['identifier' => 'someone-elses-app', 'total_cost_cents' => 134],
        ]);

        [$cloud] = app(SummarizeHostingSpend::class)->handle();

        $this->assertSame(1234, $cloud['total_cents']);
        $this->assertSame('USD', $cloud['currency']);
        $this->assertSame([
            ['project_id' => $blog->id, 'name' => 'Blog', 'cents' => 800],
            ['project_id' => $shop->id, 'name' => 'Acme Shop', 'cents' => 300],
            ['project_id' => null, 'name' => 'someone-elses-app', 'cents' => 134],
        ], $cloud['apps']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer cloud-token'));
    }

    public function test_the_figures_are_kept_for_a_while_instead_of_asked_on_every_visit()
    {
        $this->fakeUsage([]);

        app(SummarizeHostingSpend::class)->handle();
        app(SummarizeHostingSpend::class)->handle();

        Http::assertSentCount(1);
    }

    public function test_a_host_that_does_not_answer_is_shown_as_unknown_and_asked_again_next_time()
    {
        Http::fake(['cloud.laravel.com/*' => Http::response(['message' => 'Server error'], 500)]);

        [$cloud] = app(SummarizeHostingSpend::class)->handle();
        app(SummarizeHostingSpend::class)->handle();

        $this->assertTrue($cloud['error']);
        $this->assertNull($cloud['total_cents']);
        Http::assertSentCount(2);
    }

    public function test_without_cloud_settings_nothing_is_asked()
    {
        config(['builder.publishing.laravel_cloud.token' => null]);
        Http::fake();

        $this->assertSame([], app(SummarizeHostingSpend::class)->handle());
        Http::assertNothingSent();
    }

    public function test_the_attention_page_shows_hosting_spend()
    {
        config(['operations.operators' => ['ops@example.com']]);
        $this->fakeUsage([['identifier' => 'app-1', 'total_cost_cents' => 300]], total: 300);

        $this->actingAs(User::factory()->create(['email' => 'ops@example.com']))
            ->get(route('operations.attention'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('attention.hosting.0.host', 'laravel_cloud')
                ->where('attention.hosting.0.total_cents', 300));
    }
}
