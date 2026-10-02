<?php

namespace App\Console\Commands;

use App\Actions\Previews\StopPreview;
use App\Enums\PreviewStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Preview;
use App\Models\Runner;
use App\Models\Workspace;
use App\Workspaces\Boxes\Providers\PoolProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('runners:remove {name : The machine\'s name} {--gone : The machine is gone for good: close its workspaces here and remove it}')]
#[Description('Remove a runner machine from the pool; one that still holds workspaces gets no new ones until they close')]
class RemoveRunner extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PoolProvider $pool, StopPreview $stopPreview): int
    {
        $runner = Runner::query()->where('name', (string) $this->argument('name'))->first();

        if ($runner === null) {
            $this->components->error("There is no runner named [{$this->argument('name')}].");

            return self::FAILURE;
        }

        if ($this->option('gone')) {
            return $this->removeGone($runner, $pool, $stopPreview);
        }

        // Its workspaces hold people's open work, and their box names lead
        // back to this runner by name. Removing it would strand them, so it
        // drains first: it keeps running them but gets no new ones.
        $holding = $pool->load($runner);

        if ($holding > 0) {
            if ($runner->draining_at === null) {
                $runner->update(['draining_at' => now()]);
            }

            $this->components->warn("Runner [{$runner->name}] gets no new workspaces now, but it still holds {$holding}. Keep the runner running so they can close, and run this command again later.");

            return self::FAILURE;
        }

        $runner->delete();
        $this->components->info("Runner [{$runner->name}] is removed. Its token no longer works. You can now stop the runner on the machine.");

        return self::SUCCESS;
    }

    /**
     * Remove a machine that went away with its workspaces still on it. They
     * cannot close on the machine, so they close here: their previews stop,
     * and the runs on them fail on their next command. A machine that still
     * asks for work is not gone, so it is refused.
     */
    protected function removeGone(Runner $runner, PoolProvider $pool, StopPreview $stopPreview): int
    {
        if (Runner::query()->online()->whereKey($runner->id)->exists()) {
            $this->components->error("Runner [{$runner->name}] still asks for work, so it is not gone. Stop it first, or remove it without --gone.");

            return self::FAILURE;
        }

        $closed = 0;

        $pool->workspacesOn($runner)->each(function (Workspace $workspace) use ($stopPreview, &$closed) {
            $workspace->update([
                'status' => WorkspaceStatus::Destroyed,
                'destroyed_at' => now(),
                'cleanup_error' => 'Its machine went away and was removed.',
            ]);

            Preview::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
                ->each(fn (Preview $preview) => $stopPreview->handle($preview, __('This is our fault: the machine your app ran on went away. Start it again.')));

            $closed++;
        });

        $runner->delete();
        $this->components->info("Runner [{$runner->name}] is removed, and {$closed} workspace(s) on it are closed. Its token no longer works.");

        return self::SUCCESS;
    }
}
