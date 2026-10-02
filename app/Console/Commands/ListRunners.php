<?php

namespace App\Console\Commands;

use App\Models\Runner;
use App\Workspaces\Boxes\Providers\PoolProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('runners:list')]
#[Description('Show the runner machines in the pool: whether each is online, draining, and how many workspaces it holds')]
class ListRunners extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PoolProvider $pool): int
    {
        $runners = Runner::query()->orderBy('name')->get();

        if ($runners->isEmpty()) {
            $this->components->info('There are no runner machines. Add one with runners:add.');

            return self::SUCCESS;
        }

        $online = Runner::query()->online()->pluck('id');

        $this->table(
            ['Machine', 'State', 'Workspaces', 'Free disk', 'Last asked for work', 'Previews at'],
            $runners->map(fn (Runner $runner) => [
                $runner->name,
                match (true) {
                    $runner->last_seen_at === null => 'starting',
                    ! $online->contains($runner->id) => 'offline',
                    $runner->draining_at !== null => 'draining',
                    default => 'online',
                },
                $pool->load($runner),
                $runner->disk_free_mb === null ? '-' : number_format($runner->disk_free_mb / 1024, 1).' GB',
                $runner->last_seen_at?->diffForHumans() ?? 'never',
                $runner->service_host ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
