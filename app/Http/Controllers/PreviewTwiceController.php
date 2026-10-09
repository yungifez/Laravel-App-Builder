<?php

namespace App\Http\Controllers;

use App\Actions\Previews\SendPreviewTwice;
use App\Http\Requests\PreviewTwiceStoreRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

class PreviewTwiceController extends Controller
{
    /**
     * Send the last form the app on show twice at once, by one person or
     * two, and say what came of it.
     */
    public function store(PreviewTwiceStoreRequest $request, Project $project, SendPreviewTwice $sendPreviewTwice): JsonResponse
    {
        return response()->json($sendPreviewTwice->handle($project, $request->boolean('two_people')));
    }
}
