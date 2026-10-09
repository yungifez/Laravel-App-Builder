<?php

namespace App\Http\Controllers;

use App\Actions\Previews\ChangePreviewRow;
use App\Actions\Previews\DeletePreviewRow;
use App\Http\Requests\PreviewRowDestroyRequest;
use App\Http\Requests\PreviewRowUpdateRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class PreviewRowController extends Controller
{
    /**
     * Change one value of one row the app on show saved, such as a name
     * typed wrong while the owner tried it.
     */
    public function update(PreviewRowUpdateRequest $request, Project $project, ChangePreviewRow $changePreviewRow): RedirectResponse
    {
        $changePreviewRow->handle(
            $project,
            $request->string('table')->toString(),
            $request->string('row')->toString(),
            $request->string('column')->toString(),
            $request->filled('value') ? $request->string('value')->toString() : null,
        );

        return back();
    }

    /**
     * Delete one row the app on show saved, such as someone who signed up
     * while the owner tried it.
     */
    public function destroy(PreviewRowDestroyRequest $request, Project $project, DeletePreviewRow $deletePreviewRow): RedirectResponse
    {
        $deletePreviewRow->handle($project, $request->string('table')->toString(), $request->string('row')->toString());

        return back();
    }
}
