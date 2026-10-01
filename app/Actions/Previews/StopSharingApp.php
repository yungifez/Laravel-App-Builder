<?php

namespace App\Actions\Previews;

use App\Models\Project;
use App\Previews\PreviewGateway;
use Illuminate\Support\Facades\Cache;

class StopSharingApp
{
    /**
     * End the link the owner shared, and the sessions it opened, so no one
     * else can try the app until the owner shares it again.
     */
    public function handle(Project $project): void
    {
        $project->update([
            'share_token' => null,
            'share_token_hash' => null,
            'share_expires_at' => null,
        ]);

        foreach ($project->previews()->get() as $preview) {
            Cache::forget(PreviewGateway::sharedSessionsKey($preview));
        }
    }
}
