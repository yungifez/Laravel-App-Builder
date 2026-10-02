<?php

namespace App\Http\Controllers;

use App\Actions\Previews\SetPreviewFault;
use App\Http\Requests\PreviewFaultUpdateRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class PreviewFaultController extends Controller
{
    /**
     * Make one kind of thing fail in the app on show, or let all work again.
     */
    public function update(PreviewFaultUpdateRequest $request, Project $project, SetPreviewFault $setPreviewFault): RedirectResponse
    {
        $setPreviewFault->handle($project, $request->string('fault')->toString());

        return back();
    }
}
