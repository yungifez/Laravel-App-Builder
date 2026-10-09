<?php

namespace App\Http\Controllers;

use App\Actions\Features\CorrectWrittenCase;
use App\Http\Requests\FeatureRequestCaseCorrectionRequest;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;

class FeatureRequestCaseCorrectionController extends Controller
{
    /**
     * Make a change again without a case the owner did not mean.
     */
    public function store(FeatureRequestCaseCorrectionRequest $request, FeatureRequest $featureRequest, CorrectWrittenCase $correctWrittenCase): RedirectResponse
    {
        $retry = $correctWrittenCase->handle($featureRequest, $request->user(), $request->integer('criterion'), $request->string('kind')->value(), $request->string('note')->value());

        return to_route('projects.show', ['project' => $retry->project, 'change' => $retry->uuid]);
    }
}
