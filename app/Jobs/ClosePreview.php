<?php

namespace App\Jobs;

use App\Actions\Previews\StopPreview;
use App\Models\Preview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Remove the box of a preview already marked stopped, away from the
 * request that found its app silent. If this fails, the idle workspace
 * reaper removes the box later.
 */
class ClosePreview implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Preview $preview)
    {
        $this->onQueue(config('builder.preview.queue'));
    }

    /**
     * Execute the job.
     */
    public function handle(StopPreview $stopPreview): void
    {
        $stopPreview->handle($this->preview);
    }
}
