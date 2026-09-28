<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\ReshapeVisualElement;
use App\Http\Requests\NewPartStoreRequest;
use App\Models\Project;
use App\Models\VisualEdit;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class NewPartController extends Controller
{
    /**
     * Put a new part, such as a heading or a button, right after a part.
     */
    public function store(NewPartStoreRequest $request, Project $project, ReshapeVisualElement $reshapeVisualElement): RedirectResponse
    {
        $added = $reshapeVisualElement->handle(
            $request->preview(),
            $request->user(),
            $request->location(),
            VisualEdit::ADD,
            $request->validated('revision'),
            $request->validated('part'),
        );

        // Where the new part is, so the inspector picks it.
        Inertia::flash('moved', ['target' => (string) $added['location'], 'instance' => $added['location']->instance]);

        return back();
    }
}
