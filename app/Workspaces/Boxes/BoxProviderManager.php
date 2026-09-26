<?php

namespace App\Workspaces\Boxes;

use App\Workspaces\Boxes\Contracts\BoxProvider;
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
}
