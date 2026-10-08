<?php

namespace Tests\Feature\Publishing;

use App\Enums\DeploymentStatus;
use App\Http\Requests\ProjectPublishingUpdateRequest;
use App\Jobs\ConfirmDeployment;
use App\Models\Deployment;
use App\Models\Project;
use App\Publishing\LiveAppClient;
use App\Publishing\PublicAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.publishing.allow_local_remotes' => false]);
        Queue::fake();
    }

    public function test_named_hosts_are_refused_if_any_address_is_private_reserved_or_missing()
    {
        foreach ([[], ['127.0.0.1'], ['10.0.0.1'], ['169.254.169.254'], ['100.64.0.1'], ['::1'], ['fc00::1'], ['fe80::1'], ['fec0::1'], ['::ffff:127.0.0.1'], ['64:ff9b::7f00:1'], ['224.0.0.1'], ['ff02::1'], ['not-an-ip'], ['1.1.1.1', '192.168.0.1'], ['1.1.1.1', 'fec0::1']] as $ips) {
            $this->partialMock(PublicAddress::class)->shouldReceive('resolve')->with('app.example.com')->andReturn($ips);
            $this->assertFalse(ProjectPublishingUpdateRequest::acceptableAddress('https://app.example.com'), json_encode($ips));
        }

        $this->partialMock(PublicAddress::class)->shouldReceive('resolve')->with('app.example.com')->andReturn(['1.1.1.1', '2606:4700:4700::1111']);
        $this->assertTrue(ProjectPublishingUpdateRequest::acceptableAddress('https://app.example.com'));
    }

    public function test_local_host_suffixes_and_credentials_are_refused_before_dns_resolution()
    {
        $this->partialMock(PublicAddress::class)->shouldNotReceive('resolve');

        foreach (['https://host.LOCALHOST', 'https://host.INTERNAL.', 'https://user:secret@app.example.com', 'https://127.0.0.1', 'http://app.example.com', 'https://localhost', 'https://é.example.com', 'https://%61pp.example.com'] as $address) {
            $this->assertFalse(ProjectPublishingUpdateRequest::acceptableAddress($address));
        }
    }

    public function test_public_connections_are_pinned_for_health_and_sign_in_checks()
    {
        $this->partialMock(PublicAddress::class)->shouldReceive('resolve')->with('app.example.com')->andReturn(['1.1.1.1', '2606:4700:4700::1111']);
        $options = [];
        Http::globalMiddleware(function ($handler) use (&$options) {
            return function ($request, array $sentOptions) use ($handler, &$options) {
                $options[] = $sentOptions;

                return $handler($request, $sentOptions);
            };
        });
        Http::fake(['app.example.com/*' => fn (Request $request) => Http::response('', $request->method() === 'POST' ? 422 : 200)]);
        $deployment = $this->deployment();

        app()->call([new ConfirmDeployment($deployment), 'handle']);

        $this->assertSame(DeploymentStatus::Published, $deployment->refresh()->status);
        $this->assertCount(4, $options);

        foreach ($options as $sentOptions) {
            $this->assertSame(['app.example.com:443:1.1.1.1,[2606:4700:4700::1111]'], $sentOptions['curl'][CURLOPT_RESOLVE]);
            $this->assertSame('', $sentOptions['proxy']);
            $this->assertSame('', $sentOptions['curl'][CURLOPT_PROXY]);
            $this->assertTrue($sentOptions['verify']);
            $this->assertFalse($sentOptions['allow_redirects']);
        }
    }

    public function test_an_address_that_becomes_private_after_validation_is_not_requested()
    {
        $this->partialMock(PublicAddress::class)->shouldReceive('resolve')->with('app.example.com')->andReturn(['1.1.1.1'], ['127.0.0.1']);
        $this->assertTrue(ProjectPublishingUpdateRequest::acceptableAddress('https://app.example.com'));
        Http::fake();
        $deployment = $this->deployment();

        app()->call([new ConfirmDeployment($deployment), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(DeploymentStatus::NeedsAttention, $deployment->refresh()->status);
        $this->assertSame([null, null], array_column($deployment->health, 'status'));
    }

    public function test_sign_in_posts_also_recheck_the_address()
    {
        $this->partialMock(PublicAddress::class)->shouldReceive('resolve')->with('app.example.com')
            ->andReturn(['1.1.1.1'], ['1.1.1.1'], ['1.1.1.1'], ['::1']);
        Http::fake(['app.example.com/*' => Http::response('', 200)]);
        $deployment = $this->deployment();

        app()->call([new ConfirmDeployment($deployment), 'handle']);

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertSame(DeploymentStatus::NeedsAttention, $deployment->refresh()->status);
        $this->assertSame('auth.sign-in', $deployment->health[2]['key']);
        $this->assertNull($deployment->health[2]['status']);
    }

    public function test_the_port_is_pinned_and_local_connections_still_require_the_development_setting()
    {
        $this->partialMock(PublicAddress::class)->shouldReceive('resolve')->with('app.example.com')->andReturn(['1.1.1.1']);
        $options = app(LiveAppClient::class)->request('https://app.example.com:8443/base')->getOptions();
        $this->assertSame(['app.example.com:8443:1.1.1.1'], $options['curl'][CURLOPT_RESOLVE]);

        config(['builder.publishing.allow_local_remotes' => true]);
        $this->assertTrue(ProjectPublishingUpdateRequest::acceptableAddress('http://127.0.0.1'));
        $this->assertArrayNotHasKey('curl', app(LiveAppClient::class)->request('http://127.0.0.1')->getOptions());
    }

    protected function deployment(): Deployment
    {
        $project = Project::factory()->create(['live_url' => 'https://app.example.com']);

        return Deployment::factory()->for($project)->create([
            'user_id' => $project->user_id,
            'host' => 'git',
            'status' => DeploymentStatus::Confirming,
            'pushed_at' => now()->subDay(),
        ]);
    }
}
