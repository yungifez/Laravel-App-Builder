<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\ChangeVisualText;
use App\Http\Requests\VisualTextStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class VisualTextController extends Controller
{
    /**
     * Change the words a part shows.
     */
    public function store(VisualTextStoreRequest $request, Project $project, ChangeVisualText $changeVisualText): RedirectResponse
    {
        $changeVisualText->handle(
            $request->preview(),
            $request->user(),
            $request->location(),
            $request->validated('before'),
            trim($request->validated('text')),
            $request->validated('revision'),
        );

        return back();
    }
}
