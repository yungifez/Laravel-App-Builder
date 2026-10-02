<?php

namespace Tests\Feature\Workspaces;

use App\Workspaces\Machines\Clouds\HetznerCloud;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HetznerCloudTest extends TestCase
{
    public function test_machines_are_listed_across_pages_and_only_from_this_pool()
    {
        Http::fake([
            'api.hetzner.cloud/v1/servers?*page=1*' => Http::response(['servers' => [['id' => 1, 'labels' => ['builder-pool' => 'prod', 'builder-runner' => 'ma']]], 'meta' => ['pagination' => ['next_page' => 2]]]),
            'api.hetzner.cloud/v1/servers?*page=2*' => Http::response(['servers' => [['id' => 2, 'labels' => ['builder-pool' => 'prod', 'builder-runner' => 'mb']]], 'meta' => ['pagination' => ['next_page' => null]]]),
        ]);

        $this->assertSame(['1' => 'ma', '2' => 'mb'], $this->cloud()->machines());

        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'label_selector=builder-pool=prod'));
    }

    public function test_a_machine_already_gone_is_not_an_error_but_a_refusal_is()
    {
        Http::fake([
            'api.hetzner.cloud/v1/servers/1' => Http::response(['error' => ['message' => 'not found']], 404),
            'api.hetzner.cloud/v1/servers/2' => Http::response(['error' => ['message' => 'server is locked']], 423),
        ]);

        $this->cloud()->delete('1');

        $this->expectExceptionMessage('Hetzner could not delete a machine: server is locked');
        $this->cloud()->delete('2');
    }

    public function test_a_cloud_without_a_token_sends_nothing()
    {
        Http::fake();

        try {
            (new HetznerCloud('', 'cx33', 'docker-ce', 'fsn1', 'prod'))->machines();
            $this->fail('A cloud without a token must refuse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('WORKSPACE_MACHINES_HETZNER_TOKEN', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_previews_listen_on_the_private_network_when_there_is_one()
    {
        $this->assertStringContainsString('metadata/private-networks', (new HetznerCloud('t', 'cx33', 'docker-ce', 'fsn1', 'prod', network: '12'))->serviceHostCommand());
        $this->assertStringContainsString('hostname -I', $this->cloud()->serviceHostCommand());
    }

    protected function cloud(): HetznerCloud
    {
        return new HetznerCloud('hetzner-test-token', 'cx33', 'docker-ce', 'fsn1', 'prod');
    }
}
