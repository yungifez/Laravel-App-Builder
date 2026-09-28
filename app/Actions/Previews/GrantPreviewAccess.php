<?php

namespace App\Actions\Previews;

use App\Enums\PreviewStatus;
use App\Models\Preview;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GrantPreviewAccess
{
    /**
     * Issue a single-use grant for the preview and return the URL that
     * exchanges it for a session on the preview host, opening the given
     * page of the app (its front page when none is given). A cookie of the
     * app's own, such as a session one of its people is signed in with,
     * is set with the grant.
     *
     * @param  array{name: string, value: string, minutes: int}|null  $cookie
     *
     * @throws ValidationException when the preview is not running.
     */
    public function handle(Preview $preview, ?string $path = null, ?array $cookie = null): string
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

        if ($cookie !== null) {
            Cache::put(self::cookieKey($preview, $grant), $cookie, now()->addSeconds((int) config('builder.preview.grant_seconds')));
        }

        return $preview->url('/__builder/session').'?'.http_build_query(array_filter(['grant' => $grant, 'to' => $path]));
    }

    /**
     * Where the cookie set with a grant waits, for as long as the grant.
     */
    public static function cookieKey(Preview $preview, string $grant): string
    {
        return "previews:{$preview->id}:grant-cookie:".hash('sha256', $grant);
    }
}
