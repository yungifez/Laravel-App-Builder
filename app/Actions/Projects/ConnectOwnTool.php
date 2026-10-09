<?php

namespace App\Actions\Projects;

use App\Models\Project;

/**
 * Let the owner's own Claude Code or Codex write every change to the app
 * (architecture §11, "Workers"). We still plan each change, and check and
 * review what their tool hands back; only the writing moves to them. The
 * tool runs on the owner's computer with their own sign-in, which we never
 * see.
 */
class ConnectOwnTool
{
    /**
     * Make a connection that opens the app's changes waiting for the owner's
     * tool and nothing else. A new connection closes the earlier one. It
     * lapses after "agents.workers.project_days" or when the owner
     * disconnects. The plain token is returned once; only its hash is kept.
     */
    public function handle(Project $project): string
    {
        $project->tokens()->delete();

        return $project->createToken('own-tool', ['project'], now()->addDays((int) config('builder.agents.workers.project_days')))->plainTextToken;
    }

    /**
     * Determine if the owner's tool writes the app's new changes.
     */
    public static function connected(Project $project): bool
    {
        return $project->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
