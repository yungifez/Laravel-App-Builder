<?php

namespace App\Http\Controllers;

use App\Actions\Features\RequestStepChange;
use App\Http\Requests\StepChangeStoreRequest;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;

class FeatureRequestStepChangeController extends Controller
{
    /**
     * Ask for a change to one step of the generated feature.
     */
    public function store(StepChangeStoreRequest $request, FeatureRequest $featureRequest, RequestStepChange $requestStepChange): RedirectResponse
    {
        $followUp = $requestStepChange->handle(
            $featureRequest,
            $request->user(),
            $request->validated('step'),
            $request->validated('prompt'),
        );

        return to_route('projects.show', ['project' => $followUp->project_id, 'change' => $followUp->id]);
    }
}
