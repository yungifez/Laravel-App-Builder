<?php

namespace App\Console\Commands;

use App\Models\Runner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('runners:add {name : A short name for the machine, in lowercase letters and digits}')]
#[Description('Add a runner machine to the pool and show its token once')]
class AddRunner extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $validator = Validator::make(['name' => $name], [
            // Box names start with the runner's name and "--", so the name
            // itself holds no dashes.
            'name' => ['required', 'regex:/^[a-z0-9]{1,30}$/', 'unique:runners,name'],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('name'));

            return self::FAILURE;
        }

        $token = Str::random(64);
        Runner::query()->create(['name' => $name, 'token_hash' => Runner::hashToken($token)]);

        $this->components->info("Runner [{$name}] is added. Its token is shown only now.");
        $this->line('Start the runner on the machine with:');
        $this->newLine();
        $this->line('  RUNNER_URL='.config('app.url'));
        $this->line("  RUNNER_TOKEN={$token}");
        $this->line('  RUNNER_SERVICE_HOST=<the machine\'s private address>');

        return self::SUCCESS;
    }
}
