<?php

namespace App\Workspaces;

use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\Drivers\DockerDriver;
use App\Workspaces\Drivers\LocalDriver;
use Illuminate\Support\Manager;
use RuntimeException;

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
     * Create the local directory workspace driver.
     *
     * @throws RuntimeException in production unless the driver is explicitly allowed there.
     */
    public function createLocalDriver(): WorkspaceDriver
    {
        /** @var array{root: string, env_passthrough: list<string>, allow_in_production?: bool} $config */
        $config = $this->config->get('workspaces.drivers.local');

        if ($this->container->environment('production') && ! ($config['allow_in_production'] ?? false)) {
            throw new RuntimeException('The local workspace driver runs customer code unisolated and is disabled in production. Use another workspace driver, or set WORKSPACE_LOCAL_IN_PRODUCTION=true on a host dedicated to trusted code.');
        }

        return new LocalDriver(root: $config['root'], envPassthrough: $config['env_passthrough']);
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
