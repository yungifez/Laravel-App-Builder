<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectNameUpdateRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class ProjectNameController extends Controller
{
    /**
     * Rename the app. Its repository and host keep the names they were
     * made with; they are found by what was recorded, not by this name.
     */
    public function update(ProjectNameUpdateRequest $request, Project $project): RedirectResponse
    {
        $project->update(['name' => $request->validated('name')]);

        return back();
    }
}
