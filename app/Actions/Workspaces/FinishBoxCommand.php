<?php

namespace App\Actions\Workspaces;

use App\Enums\BoxCommandStatus;
use App\Models\BoxCommand;
use Illuminate\Support\Facades\Date;

class FinishBoxCommand
{
    /**
     * Record a runner's result for a command it took. A repeated or late
     * result changes nothing, and the payload, which may hold credentials,
     * is removed.
     *
     * @param  array<string, mixed>  $result
     */
    public function handle(BoxCommand $command, array $result): void
    {
        BoxCommand::whereKey($command->id)
            ->where('status', BoxCommandStatus::Claimed)
            ->update([
                'status' => BoxCommandStatus::Finished,
                'payload' => null,
                'result' => json_encode($result),
                'finished_at' => Date::now(),
            ]);
    }
}
