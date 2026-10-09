<?php

namespace App\Actions\Workspaces;

use App\Enums\BoxCommandStatus;
use App\Models\BoxCommand;
use Illuminate\Support\Facades\Date;

class LoseRunnerCommands
{
    /**
     * Record that a runner which has just started lost the commands it had
     * taken: a restart stops them, and it never sends their results. Whoever
     * waits for one learns at once, rather than when its time runs out.
     */
    public function handle(string $runner): int
    {
        return BoxCommand::where('runner', $runner)
            ->where('status', BoxCommandStatus::Claimed)
            ->update([
                'status' => BoxCommandStatus::Lost,
                'payload' => null,
                'result' => json_encode(['exit_code' => null, 'output' => '', 'error_output' => 'The runner restarted while the command ran.', 'timed_out' => true, 'duration_ms' => 0]),
                'finished_at' => Date::now(),
            ]);
    }
}
