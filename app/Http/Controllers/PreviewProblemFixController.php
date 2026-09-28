<?php

namespace App\Http\Controllers;

use App\Actions\Previews\RequestPreviewProblemFix;
use App\Http\Requests\PreviewProblemFixStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class PreviewProblemFixController extends Controller
{
    /**
     * Ask for a problem the owner ran into while trying the app to be fixed.
     */
    public function store(PreviewProblemFixStoreRequest $request, Project $project, RequestPreviewProblemFix $requestPreviewProblemFix): RedirectResponse
    {
        $fix = $requestPreviewProblemFix->handle($project, $request->user(), $request->string('problem')->toString());

        return to_route('projects.show', ['project' => $project, 'change' => $fix->id]);
    }
}
