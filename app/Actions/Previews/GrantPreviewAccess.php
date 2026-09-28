<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\Preview;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GrantPreviewAccess
{
    /**
     * Issue a single-use grant for the preview and return the URL that
     * exchanges it for a session on the preview host, opening the given
     * page of the app (its front page when none is given).
     *
     * @throws ValidationException when the preview is not running.
     */
    public function handle(Preview $preview, ?string $path = null): string
    {
        if ($preview->status !== PreviewStatus::Ready) {
            throw ValidationException::withMessages([
                'preview' => __('The preview is not running.'),
            ]);
        }

        $grant = Str::random(48);

        $preview->update([
            'grant_hash' => hash('sha256', $grant),
            'grant_expires_at' => now()->addSeconds((int) config('builder.preview.grant_seconds')),
        ]);

        return $preview->url('/__builder/session').'?'.http_build_query(array_filter(['grant' => $grant, 'to' => $path]));
    }
}
