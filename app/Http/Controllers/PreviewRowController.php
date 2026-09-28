<?php

namespace App\Http\Controllers;

use App\Actions\Previews\DeletePreviewRow;
use App\Http\Requests\PreviewRowDestroyRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class PreviewRowController extends Controller
{
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
