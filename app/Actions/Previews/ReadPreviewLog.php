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

        // The builder asks every few seconds while the owner looks; the
        // workspace is read at most once in that time.
        return Cache::remember("previews:{$preview->id}:log", now()->addSeconds(2), fn () => (string) rescue(
            fn () => $this->workspaces->driver($workspace->driver)->readFile((string) $workspace->driver_id, Config::string('builder.preview.log'), self::TAIL_BYTES),
            '',
            report: false,
        ));
    }
}
