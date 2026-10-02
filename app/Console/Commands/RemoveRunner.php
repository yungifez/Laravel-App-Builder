<?php

namespace App\Console\Commands;

use App\Models\Runner;
use App\Workspaces\Boxes\Providers\PoolProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('runners:remove {name : The machine\'s name}')]
#[Description('Remove a runner machine from the pool; one that still holds workspaces gets no new ones until they close')]
class RemoveRunner extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PoolProvider $pool): int
    {
        $runner = Runner::query()->where('name', (string) $this->argument('name'))->first();

        if ($runner === null) {
            $this->components->error("There is no runner named [{$this->argument('name')}].");

            return self::FAILURE;
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
}
