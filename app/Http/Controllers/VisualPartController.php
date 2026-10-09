<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\ReshapeVisualElement;
use App\Http\Requests\VisualPartRequest;
use App\Models\Project;
use App\Models\VisualEdit;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class VisualPartController extends Controller
{
    /**
     * Put a copy of a part right after it.
     */
    public function store(VisualPartRequest $request, Project $project, ReshapeVisualElement $reshapeVisualElement): RedirectResponse
    {
        $copied = $reshapeVisualElement->handle(
            $request->preview(),
            $request->user(),
            $request->location(),
            VisualEdit::DUPLICATE,
            $request->validated('revision'),
        );

        // Where the copy is, so the inspector picks it.
        Inertia::flash('moved', ['target' => (string) $copied['location'], 'instance' => $copied['location']->instance]);

        return back();
    }

    /**
     * Take a part out of the page.
     */
    public function destroy(VisualPartRequest $request, Project $project, ReshapeVisualElement $reshapeVisualElement): RedirectResponse
    {
        $reshapeVisualElement->handle(
            $request->preview(),
            $request->user(),
            $request->location(),
            VisualEdit::REMOVE,
            $request->validated('revision'),
        );

        return back();
    }
}
