<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\MoveVisualElement;
use App\Http\Requests\VisualMoveStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class VisualMoveController extends Controller
{
    /**
     * Move a part before or after one next to it.
     */
    public function store(VisualMoveStoreRequest $request, Project $project, MoveVisualElement $moveVisualElement): RedirectResponse
    {
        $moved = $moveVisualElement->handle(
            $request->preview(),
            $request->user(),
            $request->location(),
            $request->destination(),
            $request->validated('placement'),
            $request->validated('revision'),
        );

        // Where the part is now, so the inspector keeps it selected.
        Inertia::flash('moved', ['target' => (string) $moved['location'], 'instance' => $moved['location']->instance]);

        return back();
    }
}
