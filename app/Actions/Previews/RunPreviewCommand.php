<?php

namespace App\Actions\Previews;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Preview;
use Illuminate\Validation\ValidationException;

class RunPreviewCommand
{
    public function __construct(private RunWorkspaceCommand $runWorkspaceCommand) {}

    /**
     * Run one of the app's own commands beside the app on show, with the
     * settings it runs with, and get what it printed. When the command
     * fails, the owner is told the reason given.
     *
     * @param  list<string>  $command
     *
     * @throws ValidationException
     */
    public function handle(Preview $preview, array $command, int $timeoutSeconds, string $reason): string
    {
        $workspace = $preview->workspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['app' => __('Your app is not running. Start it and try again.')]);
        }

        $result = $this->runWorkspaceCommand->handle($workspace, $command, $timeoutSeconds, $preview->environment());

        if ($result->exit_code !== 0 || $result->timed_out) {
            throw ValidationException::withMessages(['app' => $reason]);
        }

        return (string) $result->output;
    }
}
