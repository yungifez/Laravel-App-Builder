<?php

namespace Tests\Feature\Workspaces;

use App\Workspaces\Machines\Clouds\HetznerCloud;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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

    public function test_the_machine_reads_its_private_address_from_hetzners_metadata()
    {
        // The example answer from Hetzner's metadata documentation, with the
        // machine on two networks: the first network's address is used.
        $metadata = <<<'YAML'
            - ip: 10.0.0.2
              alias_ips: [10.0.0.3, 10.0.0.4]
              interface_num: 1
              mac_address: 86:00:00:2a:7d:e0
              network_id: 1234
              network_name: nw-test1
              network: 10.0.0.0/8
              subnet: 10.0.0.0/24
              gateway: 10.0.0.1
            - ip: 192.168.0.2
              alias_ips: []
              interface_num: 2
              network_id: 4321
            YAML;
        $command = (new HetznerCloud('t', 'cx33', 'docker-ce', 'fsn1', 'prod', network: '12'))->serviceHostCommand();
        $parse = substr($command, strpos($command, '|') + 1);

        $result = Process::input($metadata."\n")->run(['sh', '-c', $parse]);

        $this->assertSame("10.0.0.2\n", $result->output());
    }

    public function test_a_pool_label_that_cannot_name_a_machine_is_refused_before_any_call()
    {
        Http::fake();

        foreach (['staging_1', 'Prod', '-prod', str_repeat('a', 53)] as $label) {
            try {
                (new HetznerCloud('t', 'cx33', 'docker-ce', 'fsn1', $label))->machines();
                $this->fail("The pool label {$label} was used.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('WORKSPACE_MACHINES_POOL_LABEL', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    protected function cloud(): HetznerCloud
    {
        return new HetznerCloud('hetzner-test-token', 'cx33', 'docker-ce', 'fsn1', 'prod');
    }
}
