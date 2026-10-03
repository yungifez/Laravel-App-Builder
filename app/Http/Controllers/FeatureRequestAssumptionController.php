<?php

namespace App\Http\Controllers;

use App\Actions\Features\KeepAssumption;
use App\Http\Requests\KeepAssumptionRequest;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;

class FeatureRequestAssumptionController extends Controller
{
    /**
     * Keep something I decided for the owner as their own decision.
     */
    public function store(KeepAssumptionRequest $request, FeatureRequest $featureRequest, KeepAssumption $keepAssumption): RedirectResponse
    {
        $keepAssumption->handle($featureRequest, $request->validated('assumption'));

        return back();
    }
}
