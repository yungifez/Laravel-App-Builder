<?php

namespace App\Console\Commands;

use App\Actions\Runners\ScaleRunnerPool;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('runners:scale')]
#[Description('Start or delete one cloud machine, so the pool keeps a few free places and no empty machine')]
class ScaleRunners extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ScaleRunnerPool $scaleRunnerPool): int
    {
        foreach ($scaleRunnerPool->handle() as $line) {
            $this->components->info($line);
        }

        return self::SUCCESS;
    }
}
