<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Run;
use App\Runs\Contracts\MutatingTool;
use App\Runs\Contracts\Tool;
use App\Runs\ToolContext;
use App\Runs\WorkerDraft;
use App\Workspaces\WorkspaceManager;
use Illuminate\Validation\ValidationException;

/**
 * Read or change the app's files on our side, for a worker with no folder
 * of its own, as in a chat in the Claude app. It uses our own coder's file
 * tools, held to the same paths, on the change's own workspace, where its
 * change is laid first (architecture §11, "Workers").
 */
class UseWorkerFiles
{
    public function __construct(
        private TryWorkerChange $tryWorkerChange,
        private ExtractCandidateChange $extractCandidateChange,
        private WorkerDraft $draft,
        private WorkspaceManager $workspaces,
        private RunWorkspaceCommand $runWorkspaceCommand,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws ValidationException when the files cannot be used now.
     */
    public function handle(Run $run, Tool $tool, array $arguments): array
    {
        $workspace = $run->workspace ?? throw ValidationException::withMessages(['path' => __('The change has no copy of the app yet. This is our fault. Try again in a minute.')]);
        $lock = TryWorkerChange::lock($run);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['path' => __('A command is running on this change. Try again when it ends.')]);
        }

        try {
            $this->tryWorkerChange->lay($run, $workspace);

            /** @var list<string> $protectedPaths */
            $protectedPaths = config('builder.construction.protected_paths', []);

            $result = $tool->handle(new ToolContext($run, $workspace, $this->workspaces->driver($workspace->driver), $this->runWorkspaceCommand, $protectedPaths), $arguments);

            if ($tool instanceof MutatingTool) {
                $this->draft->keep($run, $this->extractCandidateChange->handle($workspace));
            }

            return $result;
        } finally {
            $lock->release();
        }
    }
}
