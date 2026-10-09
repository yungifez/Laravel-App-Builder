<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\ChangeVisualPicture;
use App\Http\Requests\VisualPictureStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class VisualPictureController extends Controller
{
    /**
     * Put a new picture in place of one on the page.
     */
    public function store(VisualPictureStoreRequest $request, Project $project, ChangeVisualPicture $changeVisualPicture): RedirectResponse
    {
        $changeVisualPicture->handle(
            $request->preview(),
            $request->user(),
            $request->location(),
            $request->validated('before'),
            $request->file('picture'),
            $request->validated('revision'),
        );

        return back();
    }
}
