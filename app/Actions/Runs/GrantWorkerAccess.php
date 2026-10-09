<?php

namespace App\Actions\Runs;

use App\Models\Run;

class GrantWorkerAccess
{
    /**
     * Make a token that opens one change's tools and nothing else: not the
     * owner's account, other changes or other projects. It lapses after
     * "agents.workers.minutes" and is revoked when the change ends. The plain
     * token is returned once, to hand to the worker; only its hash is kept.
     */
    public function handle(Run $run): string
    {
        return $run->createToken('worker', ['task'], now()->addMinutes((int) config('builder.agents.workers.minutes')))->plainTextToken;
    }
}
