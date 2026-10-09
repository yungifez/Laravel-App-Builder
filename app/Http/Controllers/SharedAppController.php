<?php

namespace App\Http\Controllers;

use App\Actions\Previews\OpenSharedApp;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SharedAppController extends Controller
{
    /**
     * Open the app a shared link is for, or say it is getting ready.
     */
    public function show(string $token, OpenSharedApp $openSharedApp): Response
    {
        ['project' => $project, 'url' => $url] = $openSharedApp->handle($token);

        return $url !== null
            ? Inertia::location($url)
            : Inertia::render('shared-apps/Show', ['name' => $project->name])->toResponse(request());
    }
}
