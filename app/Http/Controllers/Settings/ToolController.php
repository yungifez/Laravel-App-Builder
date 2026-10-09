<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Accounts\ListLetInTools;
use App\Actions\Accounts\SignOutTool;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ToolController extends Controller
{
    /**
     * Show the tools the person let in to work on their apps.
     */
    public function edit(Request $request, ListLetInTools $listLetInTools): Response
    {
        return Inertia::render('settings/Tools', [
            'tools' => $listLetInTools->handle($request->user()),
        ]);
    }

    /**
     * Sign one tool out of the person's apps.
     */
    public function destroy(Request $request, string $client, SignOutTool $signOutTool): RedirectResponse
    {
        $signOutTool->handle($request->user(), $client);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out. It can no longer work on your apps.')]);

        return back();
    }
}
