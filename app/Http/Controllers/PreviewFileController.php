<?php

namespace App\Http\Controllers;

use App\Actions\Previews\ReadPreviewFiles;
use App\Actions\Previews\ReadPreviewLog;
use App\Models\Project;
use App\Workspaces\WorkspaceManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class PreviewFileController extends Controller
{
    /**
     * Files larger than this are not handed over through the builder.
     */
    protected const MAX_BYTES = 20_000_000;

    /**
     * Show a picture the app on show stored, or hand over any other file
     * as a download. Only files the app lists are read. Nothing the app
     * stored runs on the builder's pages: pictures are sandboxed and other
     * files are never shown inline.
     */
    public function show(Request $request, Project $project, ReadPreviewLog $readPreviewLog, ReadPreviewFiles $readPreviewFiles, WorkspaceManager $workspaces): Response
    {
        Gate::authorize('view', $project);

        $file = collect($readPreviewFiles->handle($project) ?? [])->firstWhere('path', $request->string('path')->toString());
        $workspace = $readPreviewLog->preview($project)?->workspace;

        abort_if($file === null || $workspace === null || $file['size'] > self::MAX_BYTES, 404);

        $contents = $workspaces->driver($workspace->driver)->readFile((string) $workspace->driver_id, ReadPreviewFiles::ROOT.'/'.$file['path']);
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $type = $file['picture'] ? 'image/'.($extension === 'jpg' ? 'jpeg' : $extension) : 'application/octet-stream';

        return response($contents, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => HeaderUtils::makeDisposition($file['picture'] ? 'inline' : 'attachment', $file['name'], (string) preg_replace('/[^\x20-\x7e]|[\/\\\\%"]/', '_', $file['name'])),
            'Content-Security-Policy' => 'sandbox; default-src \'none\'; img-src \'self\'',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
