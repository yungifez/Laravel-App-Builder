<?php

namespace App\Workspaces\Machines;

use App\Workspaces\Machines\Clouds\HetznerCloud;
use App\Workspaces\Machines\Contracts\MachineCloud;
use Illuminate\Support\Manager;

/**
 * The clouds in config/workspaces.php "machines.clouds". A cloud is added
 * here as one more driver; the scaler and the commands only use the
 * MachineCloud contract.
 *
 * @method MachineCloud driver(string|null $driver = null)
 */
class MachineCloudManager extends Manager
{
    /**
     * Get the cloud the pool grows on, or null when machines are added by
     * hand.
     */
    public function getDefaultDriver(): ?string
    {
        $cloud = $this->config->get('workspaces.machines.cloud');

        return is_string($cloud) && $cloud !== '' ? $cloud : null;
    }

    /**
     * Tell whether the pool grows and shrinks on a cloud by itself.
     */
    public function enabled(): bool
    {
        return $this->getDefaultDriver() !== null;
    }

    /**
     * Create the Hetzner Cloud driver.
     */
    public function createHetznerDriver(): MachineCloud
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->config->get('workspaces.machines.clouds.hetzner', []);

        return new HetznerCloud(
            token: (string) ($config['token'] ?? ''),
            serverType: (string) ($config['server_type'] ?? 'cx33'),
            image: (string) ($config['image'] ?? 'docker-ce'),
            location: (string) ($config['location'] ?? 'fsn1'),
            pool: (string) $this->config->get('workspaces.machines.pool_label', 'builder'),
            network: filled($config['network'] ?? null) ? (string) $config['network'] : null,
            firewall: filled($config['firewall'] ?? null) ? (string) $config['firewall'] : null,
            sshKeys: array_values(array_filter(explode(',', (string) ($config['ssh_keys'] ?? '')))),
            billingMinutes: (int) ($config['billing_minutes'] ?? 60) > 0 ? (int) $config['billing_minutes'] : null,
        );
    }
}
