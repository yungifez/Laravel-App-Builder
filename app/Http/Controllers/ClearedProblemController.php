<?php

namespace App\Http\Controllers;

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
    public function store(ClearedProblemStoreRequest $request, Project $project): RedirectResponse
    {
        $project->clearedProblems()->updateOrCreate(
            ['problem' => $request->string('problem')->toString()],
            ['user_id' => $request->user()->id, 'fine' => $request->boolean('fine'), 'cleared_at' => now()],
        );

        return back();
    }

    /**
     * Put a cleared problem back in the list.
     */
    public function destroy(Project $project, string $problem): RedirectResponse
    {
        Gate::authorize('requestFeatures', $project);

        $project->clearedProblems()->where('problem', $problem)->delete();

        return back();
    }
}
