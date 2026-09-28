<?php

namespace App\Http\Controllers;

use App\Actions\Previews\StartPreviewDataAgain;
use App\Http\Requests\PreviewDataUpdateRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class PreviewDataController extends Controller
{
    /**
     * Start the data of the app on show again, with examples or empty.
     */
    public function update(PreviewDataUpdateRequest $request, Project $project, StartPreviewDataAgain $startPreviewDataAgain): RedirectResponse
    {
        $startPreviewDataAgain->handle($project, $request->string('with')->toString() === 'examples');

        return back();
    }
}
