<?php

namespace App\Actions\Previews;

use App\Models\Project;
use Illuminate\Support\Str;

class ShareApp
{
    /**
     * Get the link that lets anyone holding it try the app, without an
     * account. A link still in use is kept, so the one the owner already
     * sent goes on working; it lasts a set number of days from now.
     */
    public function handle(Project $project): string
    {
        $token = $project->share_expires_at?->isFuture() ? $project->share_token : null;
        $token ??= Str::random(40);

        $project->update([
            'share_token' => $token,
            'share_token_hash' => hash('sha256', $token),
            'share_expires_at' => now()->addDays((int) config('builder.preview.share_days')),
        ]);

        return self::url($token);
    }

    /**
     * Get the link for a token.
     */
    public static function url(string $token): string
    {
        return route('shared-apps.show', $token);
    }
}
