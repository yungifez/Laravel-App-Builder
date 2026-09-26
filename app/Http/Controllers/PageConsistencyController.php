<?php

namespace App\Http\Controllers;

use App\Actions\Features\RequestFeature;
use App\Http\Requests\PageConsistencyStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class PageConsistencyController extends Controller
{
    /**
     * Ask for the page the owner is looking at to be made consistent. It is
     * an ordinary change in their words, so they can follow and undo it
     * like any other.
     */
    public function store(PageConsistencyStoreRequest $request, Project $project, RequestFeature $requestFeature): RedirectResponse
    {
        $prompt = strtr((string) config('builder.design.consistency'), [':page' => $request->validated('path')]);

        $featureRequest = $requestFeature->handle($project, $request->user(), $prompt);

        return to_route('projects.show', ['project' => $project, 'change' => $featureRequest->id]);
    }
}
