<?php

namespace App\Http\Controllers;

use App\Actions\Projects\PackProject;
use App\Models\Run;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkerCodeController extends Controller
{
    /**
     * Send the owner's tool the app's code a change starts from, as a zip:
     * with earlier changes not kept yet, as our own writer gets it. Only
     * the signed, short-lived address from get_task opens it.
     */
    public function show(Run $run, PackProject $packProject): BinaryFileResponse
    {
        abort_if($run->status->finished(), 404);

        $featureRequest = $run->featureRequest;
        $unkept = array_map(fn ($earlier) => (string) $earlier->patch, array_slice($featureRequest->lineage(), 0, -1));
        $path = $packProject->handle($featureRequest->project, $featureRequest->base_revision, $unkept) ?? abort(404);

        return response()->download($path, $packProject->folder($featureRequest->project).'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
