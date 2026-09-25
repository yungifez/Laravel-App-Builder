<?php

namespace App\Console\Commands;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('workspaces:reap')]
#[Description('Destroy workspaces that have been idle too long or are past their maximum age')]
class ReapWorkspaces extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DestroyWorkspace $destroyWorkspace): int
    {
        $idleSince = now()->subMinutes((int) config('workspaces.lifetime.idle_minutes'));
        $reaped = 0;

        Workspace::query()->reapable($idleSince, now())->each(function (Workspace $workspace) use ($destroyWorkspace, &$reaped) {
            try {
                $destroyWorkspace->handle($workspace);
                $reaped++;
            } catch (Throwable $exception) {
                report($exception);
                $this->components->error("Could not destroy workspace [{$workspace->id}]: {$exception->getMessage()}");
            }
        });

        $this->components->info("Destroyed {$reaped} workspace(s).");

        return self::SUCCESS;
    }
}
