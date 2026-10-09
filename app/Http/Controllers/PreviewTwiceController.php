<?php

namespace App\Http\Controllers;

use App\Actions\Previews\SendPreviewTwice;
use App\Http\Requests\PreviewTwiceStoreRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

class PreviewTwiceController extends Controller
{
    /**
     * Send the last form the app on show took twice at once, and say what
     * came of it.
     */
    public function store(PreviewTwiceStoreRequest $request, Project $project, SendPreviewTwice $sendPreviewTwice): JsonResponse
    {
        return response()->json($sendPreviewTwice->handle($project));
    }
}
