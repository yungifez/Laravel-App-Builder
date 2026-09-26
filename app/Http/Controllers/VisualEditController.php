<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\ApplyVisualEdit;
use App\Http\Requests\VisualEditStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class VisualEditController extends Controller
{
    /**
     * Save a change to how one element looks.
     */
    public function store(VisualEditStoreRequest $request, Project $project, ApplyVisualEdit $applyVisualEdit): RedirectResponse
    {
        $applyVisualEdit->handle(
            $request->preview(),
            $request->user(),
            $request->location(),
            $request->validated('revision'),
            $request->validated('device'),
            $request->validated('changes'),
        );

        return back();
    }
}
