<?php

namespace App\Workspaces;

use App\Workspaces\Boxes\BoxChannel;
use App\Workspaces\Boxes\BoxProviderManager;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\Drivers\DockerDriver;
use App\Workspaces\Drivers\LocalDriver;
use App\Workspaces\Drivers\RunnerDriver;
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
     * Create the local directory workspace driver.
     */
    public function createLocalDriver(): WorkspaceDriver
    {
        /** @var array{root: string, env_passthrough: list<string>} $config */
        $config = $this->config->get('workspaces.drivers.local');

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
     * Create the driver for workspaces in boxes with a runner.
     */
    public function createRunnerDriver(): WorkspaceDriver
    {
        return new RunnerDriver(
            channel: $this->container->make(BoxChannel::class),
            provider: $this->container->make(BoxProviderManager::class)->driver(),
            fileSeconds: (int) $this->config->get('workspaces.drivers.runner.file_seconds'),
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
