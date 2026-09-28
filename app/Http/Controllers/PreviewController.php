<?php

namespace App\Http\Controllers;

use App\Actions\Previews\GrantPreviewAccess;
use App\Actions\Previews\StopPreview;
use App\Models\Preview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PreviewController extends Controller
{
    /**
     * Send the owner to the preview with a single-use grant, on the page of
     * the app they were last on when the builder says which.
     */
    public function show(Request $request, Preview $preview, GrantPreviewAccess $grantPreviewAccess): RedirectResponse
    {
        Gate::authorize('view', $preview->project);

        return redirect()->away($grantPreviewAccess->handle($preview, $request->string('to')->toString() ?: null));
    }

    /**
     * Stop the preview.
     */
    public function destroy(Preview $preview, StopPreview $stopPreview): RedirectResponse
    {
        Gate::authorize('requestFeatures', $preview->project);

        $stopPreview->handle($preview);

        return $preview->featureRequest === null
            ? to_route('projects.editor.show', $preview->project)
            : to_route('feature-requests.show', $preview->featureRequest);
    }
}
