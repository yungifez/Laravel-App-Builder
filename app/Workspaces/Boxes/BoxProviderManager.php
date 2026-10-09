<?php

namespace App\Workspaces\Boxes;

use App\Workspaces\Boxes\Contracts\BoxProvider;
use App\Workspaces\Boxes\Providers\DockerProvider;
use App\Workspaces\Boxes\Providers\PoolProvider;
use App\Workspaces\Boxes\Providers\StaticProvider;
use Illuminate\Support\Manager;

/**
 * The box providers in config/workspaces.php "boxes". A provider for a
 * hosting service is added here as one more driver; nothing else changes.
 *
 * @method BoxProvider driver(string|null $driver = null)
 */
class BoxProviderManager extends Manager
{
    /**
     * Get the default box provider name.
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('workspaces.drivers.runner.provider', 'static');
    }

    /**
     * Get the runner a token belongs to, asking every configured provider,
     * so boxes made before a switch of provider can still finish their work.
     */
    public function authenticate(string $token): ?string
    {
        foreach (array_keys((array) $this->config->get('workspaces.boxes', [])) as $provider) {
            $runner = $this->driver((string) $provider)->authenticate($token);

            if ($runner !== null) {
                return $runner;
            }
        }

        return null;
    }

    /**
     * Create the provider for one runner that is already running.
     */
    public function createStaticDriver(): BoxProvider
    {
        return new StaticProvider(
            runner: (string) $this->config->get('workspaces.boxes.static.runner'),
            token: (string) $this->config->get('workspaces.boxes.static.token'),
            serviceHost: (string) $this->config->get('workspaces.boxes.static.service_host'),
        );
    }

    /**
     * Create the provider for a pool of runners on machines of their own.
     */
    public function createPoolDriver(): BoxProvider
    {
        return new PoolProvider;
    }

    /**
     * Create the provider that makes one Docker container per workspace.
     */
    public function createDockerDriver(): BoxProvider
    {
        return new DockerProvider(
            url: rtrim((string) $this->config->get('workspaces.boxes.docker.url'), '/'),
            token: (string) $this->config->get('workspaces.boxes.docker.token'),
            controlPlaneUrl: (string) $this->config->get('workspaces.boxes.docker.control_plane_url'),
            key: (string) $this->config->get('app.key'),
        );
    }
}
