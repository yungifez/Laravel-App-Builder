<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\Project;
use App\Previews\LoggedEmails;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class ReadPreviewEmails
{
    /**
     * The end of the log that is read. Older email is not shown.
     */
    protected const TAIL_BYTES = 2_000_000;

    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Get the email the app on show has sent, newest first. A preview
     * writes email to its log instead of sending it, so the owner can read
     * it here and follow its links.
     *
     * @return list<array{id: string, sent_at: string|null, from: string, to: string, subject: string, html: string|null, text: string|null}>
     */
    public function handle(Project $project): array
    {
        $preview = $project->previews()->whereNull('feature_request_id')->where('editable', true)->latest('id')->first();
        $workspace = $preview?->workspace;

        if ($preview?->status !== PreviewStatus::Ready || $workspace === null) {
            return [];
        }

        // The builder asks every few seconds while the owner looks; the
        // workspace is read at most once in that time.
        return Cache::remember("previews:{$preview->id}:emails", now()->addSeconds(2), function () use ($workspace) {
            $log = rescue(
                fn () => $this->workspaces->driver($workspace->driver)->readFile((string) $workspace->driver_id, Config::string('builder.preview.log')),
                '',
                report: false,
            );

            return LoggedEmails::in(substr((string) $log, -self::TAIL_BYTES));
        });
    }
}
