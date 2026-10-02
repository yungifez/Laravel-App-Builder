<?php

namespace App\Actions\Projects;

use App\Models\Project;

class DisconnectOwnTool
{
    /**
     * Close the owner's tool's connection. New changes are written by us
     * again; a change already waiting for their tool keeps waiting, and the
     * owner can try it again for us to write.
     */
    public function handle(Project $project): void
    {
        $project->tokens()->delete();
    }
}
