<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\Preview;
use App\Models\Project;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class ReadPreviewLog
{
    /**
     * The end of the log that is read. What came before is not shown.
     */
    protected const TAIL_BYTES = 2_000_000;

    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Get the app on show, when it runs. While the owner tries a change, the
     * request names it as the copy, and the builder's tools read and change
     * that copy instead of the app.
     */
    public function preview(Project $project): ?Preview
    {
        $copy = request()->query('copy');

        $preview = is_string($copy) && Str::isUuid($copy)
            ? $project->featureRequests()->where('uuid', $copy)->first()?->previews()->latest('id')->first()
            : $project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();

        return $preview?->status === PreviewStatus::Ready && $preview->workspace !== null ? $preview : null;
    }

    /**
     * Get the end of the log of the app on show: the email it sent and the
     * problems it ran into. Empty while it does not run or has not written.
     */
    public function handle(Preview $preview): string
    {
        $workspace = $preview->workspace;

        if ($workspace === null) {
            return '';
        }

        $fresh = "previews:{$preview->id}:log";
        $last = "previews:{$preview->id}:log:last";

        if (Cache::has($fresh)) {
            return (string) Cache::get($last, '');
        }

        // The builder asks every few seconds from each open page, and a read
        // holds a web worker until the runner answers. Only one read runs
        // per app; the other pages get the log as last read. So the reads
        // cannot take every worker and keep the runner from claiming them.
        $lock = Cache::lock("previews:{$preview->id}:log:reading", 90);

        if (! $lock->get()) {
            return (string) Cache::get($last, '');
        }

        try {
            $log = rescue(
                fn () => $this->workspaces->driver($workspace->driver)->readFile((string) $workspace->driver_id, Config::string('builder.preview.log'), self::TAIL_BYTES),
                null,
                report: false,
            );

            if ($log !== null) {
                Cache::put($last, $log, now()->addMinutes(10));
            }

            // A read that failed is not tried again for a few polls: a runner
            // that is behind gets time to catch up.
            Cache::put($fresh, true, now()->addSeconds($log === null ? 10 : 2));

            return (string) ($log ?? Cache::get($last, ''));
        } finally {
            $lock->release();
        }
    }
}
