<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectCompatibilityUpdateRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class ProjectCompatibilityController extends Controller
{
    /**
     * Record whether changes must keep the app's old data and links
     * working. Null hands the choice back to whether anyone uses the app.
     */
    public function update(ProjectCompatibilityUpdateRequest $request, Project $project): RedirectResponse
    {
        $project->forceFill(['keep_old_working' => $request->validated('keep_old_working')])->save();

        return back();
    }
}
