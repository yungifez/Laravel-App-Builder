<?php

namespace App\Console\Commands;

use App\Actions\Runners\RetireRunner;
use App\Models\Runner;
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
    public function handle(RetireRunner $retireRunner): int
    {
        $runner = Runner::query()->where('name', (string) $this->argument('name'))->first();

        if ($runner === null) {
            $this->components->error("There is no runner named [{$this->argument('name')}].");

            return self::FAILURE;
        }

        if ($this->option('gone')) {
            // A machine that still asks for work is not gone.
            if (Runner::query()->online()->whereKey($runner->id)->exists()) {
                $this->components->error("Runner [{$runner->name}] still asks for work, so it is not gone. Stop it first, or remove it without --gone.");

                return self::FAILURE;
            }

            $closed = $retireRunner->gone($runner);
            $this->components->info("Runner [{$runner->name}] is removed, and {$closed} workspace(s) on it are closed. Its token no longer works.");

            return self::SUCCESS;
        }

        $holding = $retireRunner->handle($runner);

        if ($holding > 0) {
            $this->components->warn("Runner [{$runner->name}] gets no new workspaces now, but it still holds {$holding}. Keep the runner running so they can close, and run this command again later.");

            return self::FAILURE;
        }

        $this->components->info("Runner [{$runner->name}] is removed. Its token no longer works. You can now stop the runner on the machine.");

        return self::SUCCESS;
    }
}
