<?php

namespace App\Http\Controllers;

use App\Actions\Features\RequestFollowUp;
use App\Http\Requests\FollowUpStoreRequest;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;

class FeatureRequestFollowUpController extends Controller
{
    /**
     * Ask for more on top of the generated feature, in the same chat.
     */
    public function store(FollowUpStoreRequest $request, FeatureRequest $featureRequest, RequestFollowUp $requestFollowUp): RedirectResponse
    {
        $followUp = $requestFollowUp->handle(
            $featureRequest,
            $request->user(),
            $request->validated('prompt'),
            selection: $request->validated('selection'),
        );

        return to_route('projects.show', ['project' => $followUp->project_id, 'change' => $followUp->id]);
    }
}
