<?php

namespace App\Http\Controllers;

use App\Actions\Previews\RequestProjectPreview;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProjectPreviewController extends Controller
{
    /**
     * Start an editable preview of the project as it is now.
     */
    public function store(Project $project, RequestProjectPreview $requestProjectPreview): RedirectResponse
    {
        Gate::authorize('requestFeatures', $project);

        $requestProjectPreview->handle($project);

        return to_route('projects.editor.show', $project);
    }
}
