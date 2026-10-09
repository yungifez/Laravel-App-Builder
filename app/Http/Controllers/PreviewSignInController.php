<?php

namespace App\Http\Controllers;

use App\Actions\Previews\SignInToPreview;
use App\Http\Requests\PreviewSignInDestroyRequest;
use App\Http\Requests\PreviewSignInStoreRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

class PreviewSignInController extends Controller
{
    /**
     * Sign the app on show in as one of its people, with no password, and
     * give the address that opens it that way.
     */
    public function store(PreviewSignInStoreRequest $request, Project $project, SignInToPreview $signInToPreview): JsonResponse
    {
        return response()->json([
            'url' => $signInToPreview->handle($project, $request->string('person')->toString(), $request->string('to')->toString() ?: null),
        ]);
    }

    /**
     * Open the app on show signed out, as a visitor sees it, and give the
     * address that opens it that way.
     */
    public function destroy(PreviewSignInDestroyRequest $request, Project $project, SignInToPreview $signInToPreview): JsonResponse
    {
        return response()->json([
            'url' => $signInToPreview->visitor($project, $request->string('to')->toString() ?: null),
        ]);
    }
}
