<?php

namespace App\Runs\Contracts;

use App\Runs\ToolContext;

/**
 * A tool that changes the workspace. Calls must name the workspace revision
 * they expect, and each success moves the revision on by one.
 */
interface MutatingTool extends Tool
{
    /**
     * Work out whether an earlier call whose outcome was lost took effect.
     *
     * Return the call's result when the change is already in the workspace,
     * or null when it is not and the call may run again.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public function reconcile(ToolContext $context, array $arguments): ?array;
}
