<?php

namespace App\Http\Controllers;

use App\Actions\Previews\DeletePreviewEmails;
use App\Http\Requests\PreviewEmailDestroyRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class PreviewEmailController extends Controller
{
    /**
     * Delete emails from the list of emails the app on show sent: one, or
     * all the list shows.
     */
    public function destroy(PreviewEmailDestroyRequest $request, Project $project, DeletePreviewEmails $deletePreviewEmails): RedirectResponse
    {
        /** @var list<string> $ids */
        $ids = array_values(array_unique($request->array('emails')));

        $deletePreviewEmails->handle($project, $ids);

        return back();
    }
}
