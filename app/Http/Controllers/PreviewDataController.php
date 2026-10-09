<?php

namespace App\Http\Controllers;

use App\Actions\Previews\FillPreviewWithLots;
use App\Actions\Previews\StartPreviewDataAgain;
use App\Http\Requests\PreviewDataUpdateRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PreviewDataController extends Controller
{
    /**
     * Start the data of the app on show again, with examples or empty, or
     * add lots more examples to what it has.
     */
    public function update(PreviewDataUpdateRequest $request, Project $project, StartPreviewDataAgain $startPreviewDataAgain, FillPreviewWithLots $fillPreviewWithLots): RedirectResponse
    {
        if ($request->string('with')->toString() === 'lots') {
            Inertia::flash('toast', ['type' => 'success', 'message' => FillPreviewWithLots::words($fillPreviewWithLots->handle($project))]);

            return back();
        }

        $startPreviewDataAgain->handle($project, $request->string('with')->toString() === 'examples');

        return back();
    }
}
