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
     */
    public function handle(Project $project): void
    {
        $most = (int) config('builder.preview.max_running_per_owner');

        if ($most <= 0) {
            return;
        }

        $running = Preview::query()
            ->whereIn('status', [PreviewStatus::Starting, PreviewStatus::Ready])
            ->whereHas('project', fn ($query) => $query->where('user_id', $project->user_id))
            ->orderByRaw('coalesce(last_seen_at, created_at)')
            ->get();

        $running->take(max(0, $running->count() - $most + 1))
            ->each(fn (Preview $preview) => $this->stopPreview->handle($preview, __('Closed to make room for an app you opened later. Start it again to see it.')));
    }
}
