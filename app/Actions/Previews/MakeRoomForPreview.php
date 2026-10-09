<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\Preview;
use App\Models\Project;

/**
 * Keep each owner to a few running previews, since each one holds a server.
 * Before a new one starts, the owner's previews that were used least
 * recently stop, so opening an app always works and old tabs pay the cost.
 */
class MakeRoomForPreview
{
    public function __construct(private StopPreview $stopPreview) {}

    /**
     * Stop the owner's least recently used previews until one more fits.
     * Previews nobody opened yet go first. One started in the background
     * (automatic) never stops a preview the owner is looking at: when only
     * those are left, nothing stops and it says there is no room.
     */
    public function handle(Project $project, bool $automatic = false): bool
    {
        $most = (int) config('builder.preview.max_running_per_owner');

        if ($most <= 0) {
            return true;
        }

        $running = Preview::query()
            ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
            ->whereHas('project', fn ($query) => $query->where('user_id', $project->user_id))
            ->orderByRaw('last_seen_at is not null')
            ->orderByRaw('coalesce(last_seen_at, created_at)')
            ->get();

        $excess = $running->count() - $most + 1;

        if ($excess <= 0) {
            return true;
        }

        $watched = now()->subSeconds((int) config('builder.preview.watched_seconds'));
        $stoppable = $automatic
            ? $running->reject(fn (Preview $preview) => $preview->last_seen_at?->isAfter($watched) === true)
            : $running;

        if ($stoppable->count() < $excess) {
            return false;
        }

        $stoppable->take($excess)
            ->each(fn (Preview $preview) => $this->stopPreview->handle($preview, __('Closed to make room for an app you opened later. Start it again to see it.')));

        return true;
    }
}
