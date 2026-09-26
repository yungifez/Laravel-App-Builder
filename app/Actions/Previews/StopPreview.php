<?php

namespace App\Actions\Previews;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Enums\PreviewStatus;
use App\Models\Preview;

class StopPreview
{
    public function __construct(private DestroyWorkspace $destroyWorkspace) {}

    /**
     * Stop the preview's app, remove its workspace and end its sessions.
     */
    public function handle(Preview $preview, ?string $reason = null): void
    {
        if ($preview->workspace !== null) {
            rescue(fn () => $this->destroyWorkspace->handle($preview->workspace));
        }

        $preview->update([
            'status' => $preview->status === PreviewStatus::Failed ? PreviewStatus::Failed : PreviewStatus::Stopped,
            'grant_hash' => null,
            'grant_expires_at' => null,
            'session_hash' => null,
            'session_expires_at' => null,
            'error' => $reason ?? $preview->error,
            'stopped_at' => now(),
        ]);
    }
}
