<?php

namespace App\Http\Controllers;

use App\Models\FeatureRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeatureRequestImageController extends Controller
{
    /**
     * Show a picture the owner attached to a request.
     */
    public function show(FeatureRequest $featureRequest, int $image): StreamedResponse
    {
        Gate::authorize('view', $featureRequest->project);

        $path = $featureRequest->images[$image]['path'] ?? abort(404);

        return Storage::disk(Config::string('builder.construction.images.disk'))->response($path, headers: [
            'Cache-Control' => 'private, max-age=86400',
            // Only a picture, never a page that can run code.
            'Content-Security-Policy' => "default-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
