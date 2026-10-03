<?php

namespace App\Http\Controllers;

use App\Actions\Previews\ClearPreviewProblem;
use App\Http\Requests\ClearedProblemStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ClearedProblemController extends Controller
{
    /**
     * Clear a problem from the app's list. It shows again if the app runs
     * into it after now, unless the owner said failing is fine there.
     */
    public function store(ClearedProblemStoreRequest $request, Project $project, ClearPreviewProblem $clearPreviewProblem): RedirectResponse
    {
        $clearPreviewProblem->handle($project, $request->user(), $request->string('problem')->toString(), $request->boolean('fine'));

        return back();
    }

    /**
     * Put a cleared problem back in the list.
     */
    public function destroy(Project $project, string $problem, ClearPreviewProblem $clearPreviewProblem): RedirectResponse
    {
        Gate::authorize('requestFeatures', $project);

        $clearPreviewProblem->restore($project, $problem);

        return back();
    }
}
