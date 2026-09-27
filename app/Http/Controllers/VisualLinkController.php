<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\ChangeVisualLink;
use App\Http\Requests\VisualLinkStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class VisualLinkController extends Controller
{
    /**
     * Change where a link goes.
     */
    public function store(VisualLinkStoreRequest $request, Project $project, ChangeVisualLink $changeVisualLink): RedirectResponse
    {
        $changeVisualLink->handle(
            $request->preview(),
            $request->user(),
            $request->location(),
            $request->validated('before'),
            trim($request->validated('href')),
            $request->validated('revision'),
        );

        return back();
    }
}
