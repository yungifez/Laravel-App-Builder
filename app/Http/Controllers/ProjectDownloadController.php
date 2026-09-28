<?php

namespace App\Http\Controllers;

use App\Actions\Projects\PackProject;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectDownloadController extends Controller
{
    /**
     * Send the owner their app's code as a zip file.
     */
    public function __invoke(Project $project, PackProject $packProject): BinaryFileResponse
    {
        Gate::authorize('view', $project);

        $path = $packProject->handle($project) ?? abort(404);

        return response()->download($path, $packProject->folder($project).'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
