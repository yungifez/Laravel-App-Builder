<?php

namespace App\Actions\Workspaces;

use App\Models\Workspace;

/**
 * Whether the app in a workspace has what a setup step or check needs. A
 * step may name a file it "needs" (package.json for a Node install,
 * vendor/bin/phpstan for static analysis): an app without that file has
 * no use for the step, whatever its screens are made with, so the step
 * does not apply there instead of failing.
 */
class CheckStepNeeds
{
    /**
     * What was found, by workspace and file, so each file is looked for once.
     *
     * @var array<string, bool>
     */
    protected array $present = [];

    public function __construct(private RunWorkspaceCommand $runWorkspaceCommand) {}

    /**
     * Determine if the app in the workspace has what the step needs.
     *
     * @param  array{needs?: string}  $step
     */
    public function met(Workspace $workspace, array $step): bool
    {
        $needs = $step['needs'] ?? null;

        if ($needs === null) {
            return true;
        }

        return $this->present["{$workspace->id}|{$needs}"] ??= $this->runWorkspaceCommand->handle($workspace, ['test', '-e', $needs], 30)->exit_code === 0;
    }

    /**
     * Keep the steps whose needs the app in the workspace meets.
     *
     * @template TStep of array{needs?: string}
     *
     * @param  list<TStep>  $steps
     * @return list<TStep>
     */
    public function filter(Workspace $workspace, array $steps): array
    {
        return array_values(array_filter($steps, fn (array $step) => $this->met($workspace, $step)));
    }
}
