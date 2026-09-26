<?php

namespace App\Workspaces\Boxes\Providers;

use App\Workspaces\Boxes\Contracts\BoxProvider;
use App\Workspaces\WorkspaceSpec;

/**
 * One runner that is already running, such as the runner container in local
 * development, holding every workspace as a directory. Nothing is created or
 * destroyed here: the runner makes and removes the directories itself.
 *
 * Workspaces on one runner share its user, so this is for trusted apps only.
 */
class StaticProvider implements BoxProvider
{
    public function __construct(
        protected string $runner,
        protected string $token,
        protected string $serviceHost,
    ) {}

    public function create(WorkspaceSpec $spec): string
    {
        return $spec->name;
    }

    public function runnerFor(string $box): string
    {
        return $this->runner;
    }

    public function authenticate(string $token): ?string
    {
        return $this->token !== '' && hash_equals($this->token, $token) ? $this->runner : null;
    }

    public function serviceUrl(string $box, int $port): string
    {
        return "http://{$this->serviceHost}:{$port}";
    }

    public function destroy(string $box): void {}
}
