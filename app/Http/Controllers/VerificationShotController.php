<?php

namespace App\Http\Controllers;

use App\Models\Verification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationShotController extends Controller
{
    /**
     * Show a picture the screen check took of a changed screen.
     */
    public function show(Verification $verification, int $shot): StreamedResponse
    {
        Gate::authorize('view', $verification->featureRequest->project);

        $path = $verification->screens['shots'][$shot]['path'] ?? abort(404);

        return Storage::disk(Config::string('builder.verification.screens.shots_disk'))->response($path, headers: [
            'Cache-Control' => 'private, max-age=86400',
            // Only a picture, never a page that can run code.
            'Content-Security-Policy' => "default-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
