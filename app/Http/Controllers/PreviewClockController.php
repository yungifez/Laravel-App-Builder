<?php

namespace App\Http\Controllers;

use App\Actions\Previews\SetPreviewClock;
use App\Http\Requests\PreviewClockUpdateRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class PreviewClockController extends Controller
{
    /**
     * Move the app on show ahead in time, or back to today.
     */
    public function update(PreviewClockUpdateRequest $request, Project $project, SetPreviewClock $setPreviewClock): RedirectResponse
    {
        $setPreviewClock->handle($project, $request->string('jump')->toString());

        return back();
    }
}
