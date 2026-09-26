<?php

namespace App\Actions\Workspaces;

use App\Enums\BoxCommandStatus;
use App\Models\BoxCommand;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class ClaimBoxCommands
{
    /**
     * The most commands handed out at once.
     */
    protected const LIMIT = 20;

    /**
     * Hand a runner its queued commands, each exactly once, and the ids of
     * its running commands that should stop.
     *
     * @return array{commands: list<array{id: string, box: string, type: string, payload: array<string, mixed>, timeout_seconds: int}>, cancel: list<string>}
     */
    public function handle(string $runner): array
    {
        $claimed = DB::transaction(function () use ($runner) {
            $commands = BoxCommand::where('runner', $runner)
                ->where('status', BoxCommandStatus::Queued)
                ->oldest()
                ->limit(self::LIMIT)
                // Two polls at once never take the same command.
                ->lock('for update skip locked')
                ->get();

            BoxCommand::whereKey($commands->modelKeys())->update(['status' => BoxCommandStatus::Claimed, 'claimed_at' => Date::now()]);

            return $commands;
        });

        return [
            'commands' => array_values($claimed->map(fn (BoxCommand $command) => [
                'id' => $command->id,
                'box' => $command->box,
                'type' => $command->type,
                'payload' => $command->payload ?? [],
                'timeout_seconds' => $command->timeout_seconds,
            ])->all()),
            'cancel' => array_values(BoxCommand::where('runner', $runner)
                ->where('status', BoxCommandStatus::Claimed)
                ->whereNotNull('cancel_requested_at')
                ->get(['id'])
                ->map(fn (BoxCommand $command) => $command->id)
                ->all()),
        ];
    }
}
