<?php

namespace App\Http\Controllers;

use App\Actions\Context\FixNotesDrift;
use App\Http\Requests\ProjectNotesFixRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectNotesFixController extends Controller
{
    /**
     * Put right a problem the quick check found in the notes.
     */
    public function store(ProjectNotesFixRequest $request, Project $project, FixNotesDrift $fixNotesDrift): RedirectResponse
    {
        $fixNotesDrift->handle(
            $project,
            $request->validated('part'),
            $request->validated('remove'),
            $request->validated('revision'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fixed. I will use the corrected notes from now on.')]);

        return back();
    }
}
