<?php

namespace App\Workspaces\Machines\Clouds;

use App\Workspaces\Machines\Contracts\MachineCloud;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Runner machines on Hetzner Cloud, through its API. Each machine carries
 * two labels: "builder-pool" names the control plane's pool, so two control
 * planes in one Hetzner project never touch each other's machines, and
 * "builder-runner" names the runner the machine was started for.
 */
class HetznerCloud implements MachineCloud
{
    protected const API = 'https://api.hetzner.cloud/v1';

    /**
     * @param  list<string>  $sshKeys
     */
    public function __construct(
        protected string $token,
        protected string $serverType,
        protected string $image,
        protected string $location,
        protected string $pool,
        protected ?string $network = null,
        protected ?string $firewall = null,
        protected array $sshKeys = [],
        protected ?int $billingMinutes = 60,
    ) {}

    public function create(string $name, string $bootScript): string
    {
        $response = $this->api()->post('/servers', array_filter([
            'name' => "{$this->pool}-{$name}",
            'server_type' => $this->serverType,
            'image' => $this->image,
            'location' => $this->location,
            'user_data' => $bootScript,
            'start_after_create' => true,
            'labels' => ['builder-pool' => $this->pool, 'builder-runner' => $name],
            'networks' => $this->network === null ? null : [(int) $this->network],
            'firewalls' => $this->firewall === null ? null : [['firewall' => (int) $this->firewall]],
            'ssh_keys' => $this->sshKeys === [] ? null : $this->sshKeys,
        ], fn (mixed $value) => $value !== null));

        $id = $this->checked($response, 'start a machine')->json('server.id');

        if (! is_int($id) && ! is_string($id)) {
            throw new RuntimeException('Hetzner started a machine but did not say its id.');
        }

        return (string) $id;
    }

    public function delete(string $id): void
    {
        $response = $this->api()->delete('/servers/'.rawurlencode($id));

        if ($response->status() !== 404) {
            $this->checked($response, 'delete a machine');
        }
    }

    public function machines(): array
    {
        $machines = [];
        $page = 1;

        do {
            $response = $this->checked($this->api()->get('/servers', [
                'label_selector' => "builder-pool={$this->pool}",
                'per_page' => 50,
                'page' => $page,
            ]), 'list machines');

            foreach ((array) $response->json('servers', []) as $server) {
                $runner = $server['labels']['builder-runner'] ?? null;

                if (isset($server['id']) && is_string($runner)) {
                    $machines[(string) $server['id']] = $runner;
                }
            }

            $page = $response->json('meta.pagination.next_page');
        } while (is_int($page));

        return $machines;
    }

    public function serviceHostCommand(): string
    {
        // On a private network, previews listen on its address, which the
        // metadata service gives. Without one, they listen on the public
        // address, and the cloud firewall must keep their ports closed to
        // everyone but the control plane.
        return $this->network === null
            ? "hostname -I | awk '{print \$1}'"
            : "curl -sf http://169.254.169.254/hetzner/v1/metadata/private-networks | awk '/^- ip:/ {print \$3; exit}'";
    }

    public function billingMinutes(): ?int
    {
        return $this->billingMinutes;
    }

    protected function api(): PendingRequest
    {
        if ($this->token === '') {
            throw new RuntimeException('Hetzner has no API token. Set WORKSPACE_MACHINES_HETZNER_TOKEN.');
        }

        return Http::baseUrl(self::API)->withToken($this->token)->acceptJson()->timeout(30);
    }

    protected function checked(Response $response, string $doing): Response
    {
        if ($response->failed()) {
            throw new RuntimeException(sprintf('Hetzner could not %s: %s', $doing, $response->json('error.message') ?? $response->status()));
        }

        return $response;
    }
}
