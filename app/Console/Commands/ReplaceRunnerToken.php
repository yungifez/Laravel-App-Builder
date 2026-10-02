<?php

namespace App\Console\Commands;

use App\Models\Runner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('runners:token {name : The machine\'s name}')]
#[Description('Give a runner machine a new token and show it once; the old token stops working now')]
class ReplaceRunnerToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $runner = Runner::query()->where('name', (string) $this->argument('name'))->first();

        if ($runner === null) {
            $this->components->error("There is no runner named [{$this->argument('name')}].");

            return self::FAILURE;
        }

        $token = Str::random(64);
        $runner->update(['token_hash' => Runner::hashToken($token)]);

        $this->components->info("Runner [{$runner->name}] has a new token. It is shown only now, and the old token no longer works.");
        $this->line('Put it in the runner\'s settings on the machine and restart the runner:');
        $this->newLine();
        $this->line("  RUNNER_TOKEN={$token}");

        return self::SUCCESS;
    }
}
