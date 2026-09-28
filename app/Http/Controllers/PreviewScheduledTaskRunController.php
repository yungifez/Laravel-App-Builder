<?php

namespace App\Http\Controllers;

use App\Actions\Previews\RunPreviewScheduledTask;
use App\Http\Requests\PreviewScheduledTaskRunStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class PreviewScheduledTaskRunController extends Controller
{
    /**
     * Run a task the app on show runs on its own, now.
     */
    public function store(PreviewScheduledTaskRunStoreRequest $request, Project $project, RunPreviewScheduledTask $runPreviewScheduledTask): RedirectResponse
    {
        $runPreviewScheduledTask->handle($project, $request->string('task')->toString());

        return back();
    }
}
