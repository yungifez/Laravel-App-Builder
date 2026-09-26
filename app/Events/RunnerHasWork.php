<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The doorbell for a box runner: it has commands or cancellations waiting.
 * It carries nothing, so the runner always fetches the work itself.
 */
class RunnerHasWork implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public string $runner) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("runner.{$this->runner}")];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'work';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
