<?php

namespace App\Http\Controllers;

use App\Actions\Previews\MakePreviewPerson;
use App\Actions\Previews\SignInToPreview;
use App\Http\Requests\PreviewPersonStoreRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

class PreviewPersonController extends Controller
{
    /**
     * Make a test person in the app on show and sign in as them, so a new
     * app can be tried without signing up first.
     */
    public function store(PreviewPersonStoreRequest $request, Project $project, MakePreviewPerson $makePreviewPerson, SignInToPreview $signInToPreview): JsonResponse
    {
        $person = $makePreviewPerson->handle($project);

        return response()->json([
            'person' => $person,
            'url' => $signInToPreview->handle($project, $person['id'], $request->string('to')->toString() ?: null),
        ]);
    }
}
