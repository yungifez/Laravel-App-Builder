<?php

namespace App\Http\Controllers;

use App\Actions\Projects\StartProjectFromTemplate;
use App\Http\Requests\StartProjectRequest;
use Illuminate\Http\RedirectResponse;

class NewProjectController extends Controller
{
    /**
     * Start a new app from the template.
     */
    public function store(StartProjectRequest $request, StartProjectFromTemplate $startProjectFromTemplate): RedirectResponse
    {
        $project = $startProjectFromTemplate->handle(
            $request->user(),
            $request->validated('name'),
            $request->validated('purpose'),
            $request->design(),
        );

        // The first version is being built: open its conversation.
        $first = $project->featureRequests()->value('id');

        return to_route('projects.show', $first === null ? $project : ['project' => $project, 'change' => $first]);
    }
}
