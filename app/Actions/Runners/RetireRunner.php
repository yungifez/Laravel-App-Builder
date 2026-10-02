<?php

namespace App\Actions\Runners;

use App\Actions\Previews\StopPreview;
use App\Enums\PreviewStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Preview;
use App\Models\Runner;
use App\Models\Workspace;
use App\Workspaces\Boxes\Providers\PoolProvider;
use App\Workspaces\Machines\MachineCloudManager;

class RetireRunner
{
    public function __construct(
        private PoolProvider $pool,
        private StopPreview $stopPreview,
        private MachineCloudManager $clouds,
    ) {}

    /**
     * Remove a runner that holds no workspaces, or else drain it: it keeps
     * running them but gets no new ones. Its workspaces hold people's open
     * work, and their box names lead back to the runner by name, so
     * removing it with them would strand them. A machine a cloud started
     * is deleted there too.
     *
     * @return int the workspaces it still holds; 0 when it was removed.
     */
    public function handle(Runner $runner): int
    {
        return $this->pool->placing(function () use ($runner) {
            $holding = $this->pool->load($runner);

            if ($holding > 0) {
                if ($runner->draining_at === null) {
                    $runner->update(['draining_at' => now()]);
                }

                return $holding;
            }

            $this->remove($runner);

            return 0;
        });
    }

    /**
     * Remove a runner whose machine went away with its workspaces still on
     * it. They cannot close on the machine, so they close here: their
     * previews stop, and the runs on them fail on their next command.
     *
     * @return int the workspaces closed.
     */
    public function gone(Runner $runner): int
    {
        $closed = 0;

        $this->pool->workspacesOn($runner)->each(function (Workspace $workspace) use (&$closed) {
            $workspace->update([
                'status' => WorkspaceStatus::Destroyed,
                'destroyed_at' => now(),
                'cleanup_error' => 'Its machine went away and was removed.',
            ]);

            Preview::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
                ->each(fn (Preview $preview) => $this->stopPreview->handle($preview, __('This is our fault: the machine your app ran on went away. Start it again.')));

            $closed++;
        });

        $this->remove($runner);

        return $closed;
    }

    /**
     * Delete the runner, so its token stops working, and its machine when a
     * cloud started it. The machine goes first: should that fail, the
     * runner stays, and the next try finds it.
     */
    protected function remove(Runner $runner): void
    {
        if ($runner->cloud !== null && $runner->cloud_id !== null) {
            $this->clouds->driver($runner->cloud)->delete($runner->cloud_id);
        }

        $runner->delete();
    }
}
