<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\ChangeVisualMotion;
use App\Http\Requests\VisualMotionStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class VisualMotionController extends Controller
{
    /**
     * Change how a part moves.
     */
    public function store(VisualMotionStoreRequest $request, Project $project, ChangeVisualMotion $changeVisualMotion): RedirectResponse
    {
        $changeVisualMotion->handle(
            $request->preview(),
            $request->user(),
            $request->location(),
            $request->validated('revision'),
            (string) $request->validated('expected'),
            $request->motion(),
        );

        return back();
    }
}
