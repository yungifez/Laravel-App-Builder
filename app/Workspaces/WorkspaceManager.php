<?php

namespace App\Workspaces;

use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\Drivers\DockerDriver;
use Illuminate\Support\Manager;

/**
 * @method WorkspaceDriver driver(string|null $driver = null)
 */
class WorkspaceManager extends Manager
{
    /**
     * Get the default workspace driver name.
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('workspaces.default');
    }

    /**
     * Create the Docker workspace driver.
     */
    public function createDockerDriver(): WorkspaceDriver
    {
        /** @var array{binary: string, image: string, network: string, workdir: string} $config */
        $config = $this->config->get('workspaces.drivers.docker');

        return new DockerDriver(
            binary: $config['binary'],
            network: $config['network'],
            workdir: $config['workdir'],
        );
    }

    /**
     * Get the image configured for the given driver.
     */
    public function imageFor(string $driver): string
    {
        return (string) $this->config->get("workspaces.drivers.{$driver}.image", '');
    }
}
