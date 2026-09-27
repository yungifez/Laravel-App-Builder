<?php

namespace App\Publishing;

use App\Projects\ProjectRepository;
use App\Publishing\Contracts\PublishingHost;
use App\Publishing\Hosts\GitBranchHost;
use App\Publishing\Hosts\LaravelCloudHost;
use Illuminate\Support\Manager;

/**
 * @method PublishingHost driver(string|null $driver = null)
 */
class PublishingHostManager extends Manager
{
    /**
     * Get the default host: where Grandma's apps are published.
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('builder.publishing.host');
    }

    /**
     * Create the host that pushes to a branch the owner chose.
     */
    public function createGitDriver(): PublishingHost
    {
        return new GitBranchHost($this->container->make(ProjectRepository::class));
    }

    /**
     * Create the Laravel Cloud host.
     */
    public function createLaravelCloudDriver(): PublishingHost
    {
        return $this->container->make(LaravelCloudHost::class);
    }
}
