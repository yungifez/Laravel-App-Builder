<?php

namespace App\Workspaces\Boxes\Contracts;

use App\Workspaces\WorkspaceSpec;

/**
 * Where workspace boxes come from. A provider only creates, finds and
 * destroys boxes; everything done inside a box goes through the runner in
 * it, so no other code needs to know which provider made the box.
 */
interface BoxProvider
{
    /**
     * Create a box for a workspace and return its identifier. The box's
     * runner connects to the control plane by itself.
     */
    public function create(WorkspaceSpec $spec): string;

    /**
     * Get the name of the runner that serves a box.
     */
    public function runnerFor(string $box): string;

    /**
     * Get the name of the runner a token belongs to, or null when the token
     * is not one of this provider's.
     */
    public function authenticate(string $token): ?string;

    /**
     * Get the base URL the control plane reaches a service in the box at.
     */
    public function serviceUrl(string $box, int $port): string;

    /**
     * Destroy a box and everything in it.
     */
    public function destroy(string $box): void;
}
